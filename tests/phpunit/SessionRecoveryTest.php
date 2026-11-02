<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests;

use ArrayIterator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Iterator;
use Keboola\OneDriveWriter\Api\Api;
use Keboola\OneDriveWriter\Sheet;
use Keboola\OneDriveWriter\SheetFile;
use Microsoft\Graph\Graph;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\Test\TestLogger;

/**
 * Regression test for CFTL-875.
 *
 * Microsoft can discard rows that the Graph API accepted in a workbook session that later
 * becomes invalid. The writer used to continue with no session and report success, so the
 * sheet contained empty rows and no error was logged.
 */
class SessionRecoveryTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    public function testRowsWrittenInExpiredSessionAreWrittenAgain(): void
    {
        $logger = new TestLogger();
        $api = $this->createApi($logger, [
            // createSession
            new Response(201, [], (string) json_encode(['id' => 'session-1'])),
            // batch 1 (rows 1-2)
            new Response(200, [], '{}'),
            // batch 2 (rows 3-4) - the session is invalid
            $this->invalidSessionResponse(),
            // createSession, after the session expired
            new Response(201, [], (string) json_encode(['id' => 'session-2'])),
            // batch 2, written again by the retry
            new Response(200, [], '{}'),
            // batch 1 and batch 2, written again in the new session
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
        ]);

        $api->createWorkbookSessionId('drive-1', 'file-1');
        $api->insertRows(
            $this->createSheet(),
            false,
            $this->createRows(),
            2,
            fn(): Iterator => $this->createRows()
        );

        // Every mocked response was used: the two batches were written again.
        Assert::assertCount(7, $this->history);

        // A new session was created, and the following requests use it.
        Assert::assertSame('POST', $this->getRequest(3)->getMethod());
        Assert::assertStringContainsString('createSession', (string) $this->getRequest(3)->getUri());
        Assert::assertSame('session-2', $this->getRequest(4)->getHeaderLine('Workbook-Session-Id'));

        // The batches of the expired session are written again, to the same addresses.
        Assert::assertStringContainsString('A1:C2', urldecode((string) $this->getRequest(5)->getUri()));
        Assert::assertStringContainsString('A3:C4', urldecode((string) $this->getRequest(6)->getUri()));
        Assert::assertSame('session-2', $this->getRequest(5)->getHeaderLine('Workbook-Session-Id'));
        Assert::assertSame('session-2', $this->getRequest(6)->getHeaderLine('Workbook-Session-Id'));

        Assert::assertTrue($logger->hasWarningThatContains('writes the last 2 batches again'));
        Assert::assertTrue($logger->hasInfoThatContains('Written 2 rows again.'));
    }

    public function testJobFailsIfRowsCannotBeWrittenAgain(): void
    {
        $logger = new TestLogger();
        $api = $this->createApi($logger, [
            new Response(201, [], (string) json_encode(['id' => 'session-1'])),
            new Response(200, [], '{}'),
            $this->invalidSessionResponse(),
            new Response(201, [], (string) json_encode(['id' => 'session-2'])),
            new Response(200, [], '{}'),
        ]);

        $api->createWorkbookSessionId('drive-1', 'file-1');

        // No factory: the writer cannot read the source rows again, so it must fail
        // instead of reporting success for a sheet that can be incomplete.
        $this->expectExceptionMessageMatches('~The workbook session expired while the writer wrote the data~');
        $api->insertRows($this->createSheet(), false, $this->createRows(), 2);
    }

    private function createSheet(): Sheet
    {
        return new Sheet(new SheetFile('drive-1', 'file-1', true), 'worksheet-1', 'Sheet1', true);
    }

    private function createRows(): Iterator
    {
        return new ArrayIterator([
            ['col1', 'col2', 'col3'],
            ['a1', 'a2', 'a3'],
            ['b1', 'b2', 'b3'],
            ['c1', 'c2', 'c3'],
        ]);
    }

    private function invalidSessionResponse(): Response
    {
        return new Response(400, [], (string) json_encode([
            'error' => [
                'code' => 'invalidSession',
                'message' => 'The target session is invalid.',
            ],
        ]));
    }

    /**
     * @param Response[] $responses
     */
    private function createApi(TestLogger $logger, array $responses): Api
    {
        $graphApi = new Graph();
        $graphApi->setAccessToken('dummy-token');
        $api = new Api($graphApi, $logger);

        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->history));
        $api->setHttpClient(new Client(['handler' => $handlerStack]));

        return $api;
    }

    private function getRequest(int $index): RequestInterface
    {
        Assert::assertArrayHasKey($index, $this->history);
        $request = $this->history[$index]['request'];
        assert($request instanceof RequestInterface);
        return $request;
    }
}
