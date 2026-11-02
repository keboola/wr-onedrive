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
            // Spare responses. The assertions below count the requests.
            new Response(200, [], '{}'),
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

        // One session at the start, and one session after the API invalidated it.
        // The retry policy can evaluate the same error more than once, so this also
        // checks that one lost session creates one new session.
        Assert::assertSame(2, $this->countRequests('createSession'));

        // Batch 1 is written two times: in the lost session, and in the new session.
        Assert::assertSame(2, $this->countRequests('A1:C2'));

        // Batch 2 is written three times: the request that failed, the retry, and the
        // request that writes the rows of the lost session again.
        Assert::assertSame(3, $this->countRequests('A3:C4'));

        // No other request was sent.
        Assert::assertCount(7, $this->history, $this->describeRequests());

        // The rows of the lost session are written again after the new session was created.
        // (The session header is set through the Guzzle request options, so it is not visible
        // in the recorded PSR-7 request. The request order above is what this test checks.)
        $last = count($this->history) - 1;
        Assert::assertStringContainsString(
            'A1:C2',
            urldecode((string) $this->getRequest($last - 1)->getUri()),
            $this->describeRequests()
        );
        Assert::assertStringContainsString(
            'A3:C4',
            urldecode((string) $this->getRequest($last)->getUri()),
            $this->describeRequests()
        );

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
            // Spare responses, so that a missing response cannot hide the expected error.
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
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

    private function countRequests(string $uriPart): int
    {
        $count = 0;
        foreach (array_keys($this->history) as $index) {
            if (strpos(urldecode((string) $this->getRequest($index)->getUri()), $uriPart) !== false) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Lists the requests, so that a failed assertion shows what the writer sent.
     */
    private function describeRequests(): string
    {
        $lines = [];
        foreach (array_keys($this->history) as $index) {
            $request = $this->getRequest($index);
            $lines[] = sprintf(
                '%d: %s %s [session: %s]',
                $index,
                $request->getMethod(),
                urldecode((string) $request->getUri()),
                $request->getHeaderLine('Workbook-Session-Id') ?: '-'
            );
        }
        return implode("\n", $lines);
    }

    private function getRequest(int $index): RequestInterface
    {
        Assert::assertArrayHasKey($index, $this->history);
        $request = $this->history[$index]['request'];
        assert($request instanceof RequestInterface);
        return $request;
    }
}
