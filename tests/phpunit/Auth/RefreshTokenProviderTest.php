<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests\Auth;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Keboola\OneDriveWriter\Auth\RefreshTokenProvider;
use Keboola\OneDriveWriter\Auth\TokenDataManager;
use Keboola\OneDriveWriter\Exception\AccessTokenRefreshException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

class RefreshTokenProviderTest extends TestCase
{
    private const
        APP_ID = 'app-id',
        APP_SECRET = 'app-secret',
        // RefreshTokenProvider::RETRY_MAX_ATTEMPTS, including the initial try
        MAX_ATTEMPTS = 3;

    public function testConnectionErrorIsRetried(): void
    {
        // First attempt is killed by a connection reset, the second one succeeds
        $handler = new MockHandler([
            self::createConnectException(),
            self::createTokenResponse(),
        ]);

        $state = new ArrayObject();
        $logger = new TestLogger();
        $token = $this->createProvider($handler, $state, $logger)->get();

        Assert::assertSame('new-access-token', $token->getToken());
        Assert::assertSame('new-refresh-token', $token->getRefreshToken());
        Assert::assertCount(0, $handler, 'Both the failed and the successful attempt should be used.');
        Assert::assertArrayHasKey(TokenDataManager::STATE_AUTH_DATA_KEY, $state);

        // The retry must be visible in the job log, not silent
        Assert::assertTrue($logger->hasInfoThatContains('Retrying... [1x]'));
    }

    public function testConnectionErrorIsRethrownAfterLastAttempt(): void
    {
        // The login endpoint is down for good => the job must still fail
        $handler = new MockHandler(array_fill(0, self::MAX_ATTEMPTS, self::createConnectException()));

        try {
            $this->createProvider($handler, new ArrayObject(), new TestLogger())->get();
            Assert::fail(sprintf('Expected "%s" to be thrown.', ConnectException::class));
        } catch (ConnectException $e) {
            Assert::assertStringContainsString('Connection reset by peer', $e->getMessage());
            Assert::assertCount(0, $handler, 'All attempts should be used, and no more.');
        }
    }

    public function testInvalidTokenIsNotRetried(): void
    {
        // An expired/revoked token is not a connection problem => fail immediately, as before
        $handler = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'The refresh token has expired.',
            ])),
        ]);

        $state = new ArrayObject();

        try {
            $this->createProvider($handler, $state, new TestLogger())->get();
            Assert::fail(sprintf('Expected "%s" to be thrown.', AccessTokenRefreshException::class));
        } catch (AccessTokenRefreshException $e) {
            Assert::assertStringContainsString('reset authorization', $e->getMessage());
            Assert::assertCount(0, $handler, 'Only one attempt should be made.');
            Assert::assertArrayNotHasKey(TokenDataManager::STATE_AUTH_DATA_KEY, $state);
        }
    }

    private function createProvider(
        MockHandler $handler,
        ArrayObject $state,
        TestLogger $logger
    ): RefreshTokenProvider {
        $dataManager = new TokenDataManager(
            ['access_token' => 'old-access-token', 'refresh_token' => 'old-refresh-token'],
            $state
        );

        return new RetryTestRefreshTokenProvider(
            self::APP_ID,
            self::APP_SECRET,
            null,
            $dataManager,
            $logger,
            new Client(['handler' => HandlerStack::create($handler)])
        );
    }

    private static function createConnectException(): ConnectException
    {
        return new ConnectException(
            'cURL error 35: OpenSSL SSL_connect: Connection reset by peer in connection to ' .
            'login.microsoftonline.com:443',
            new Request('POST', 'https://login.microsoftonline.com/common/oauth2/v2.0/token')
        );
    }

    private static function createTokenResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]));
    }
}
