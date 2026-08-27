<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests\Auth;

use GuzzleHttp\ClientInterface;
use Keboola\OneDriveWriter\Auth\RefreshTokenProvider;
use Keboola\OneDriveWriter\Auth\TokenDataManager;
use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Log\LoggerInterface;
use Retry\BackOff\BackOffPolicyInterface;
use Retry\BackOff\NoBackOffPolicy;

/**
 * Test double for RefreshTokenProvider: talks to a mocked HTTP client instead of Microsoft,
 * and does not really sleep between the retry attempts.
 *
 * Not a test case itself, PHPUnit only collects "*Test.php" files.
 */
class RetryTestRefreshTokenProvider extends RefreshTokenProvider
{
    private ClientInterface $httpClient;

    public function __construct(
        string $appId,
        string $appSecret,
        ?string $authorityUrl,
        TokenDataManager $dataManager,
        LoggerInterface $logger,
        ClientInterface $httpClient
    ) {
        parent::__construct($appId, $appSecret, $authorityUrl, $dataManager, $logger);
        $this->httpClient = $httpClient;
    }

    protected function createOAuthProvider(string $appId, string $appSecret): GenericProvider
    {
        $provider = parent::createOAuthProvider($appId, $appSecret);
        $provider->setHttpClient($this->httpClient);
        return $provider;
    }

    protected function createBackOffPolicy(): BackOffPolicyInterface
    {
        // Keep the test fast, the real policy waits seconds between the attempts
        return new NoBackOffPolicy();
    }
}
