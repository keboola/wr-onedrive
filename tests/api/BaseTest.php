<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use ArrayObject;
use Keboola\OneDriveWriter\Api\Api;
use Keboola\OneDriveWriter\Api\ApiFactory;
use Keboola\OneDriveWriter\Api\GraphApiFactory;
use Keboola\OneDriveWriter\Auth\RefreshTokenProvider;
use Keboola\OneDriveWriter\Auth\TokenDataManager;
use Keboola\OneDriveWriter\Auth\TokenProvider;
use Keboola\OneDriveWriter\Auth\TokenProviderFactory;
use Keboola\OneDriveWriter\Configuration\Config;
use Keboola\OneDriveWriter\Fixtures\FixturesCatalog;
use Keboola\OneDriveWriter\Fixtures\FixturesUtils;
use Microsoft\Graph\Graph;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

abstract class BaseTest extends TestCase
{
    protected TestLogger $logger;

    protected Api $api;

    protected FixturesUtils $utils;

    protected ApiFactory $apiFactory;

    protected FixturesCatalog $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkEnvironment(['OAUTH_APP_ID', 'OAUTH_APP_SECRET', 'OAUTH_ACCESS_TOKEN', 'OAUTH_REFRESH_TOKEN']);
        $this->logger = new TestLogger();
        $this->api = $this->createApi(
            (string) getenv('OAUTH_APP_ID'),
            (string) getenv('OAUTH_APP_SECRET'),
            [
                'access_token' => (string) getenv('OAUTH_ACCESS_TOKEN'),
                'refresh_token' => (string) getenv('OAUTH_REFRESH_TOKEN'),
            ],
        );
        $this->utils = new FixturesUtils();
        $this->fixtures = FixturesCatalog::load();
    }

    protected function createApi(
        string $appId,
        string $appSecret,
        array $oauthData
    ): Api {
        $config = $this->createMock(Config::class);
        $config->method('getOAuthApiAppKey')->willReturn($appId);
        $config->method('getOAuthApiAppSecret')->willReturn($appSecret);
        $config->method('getOAuthApiData')->willReturn($oauthData);

        $state = new ArrayObject();
        $tokenProviderFactory = new TokenProviderFactory($config, $state);
        $tokenProvider = $tokenProviderFactory->create();
        $apiFactory = new ApiFactory($this->logger, $tokenProvider);
        return $apiFactory->create();
    }

    protected function createGraphApi(): Graph
    {
        $state = new ArrayObject();
        $tokenProvider = $this->createRefreshTokenProvider($state);
        $graphApiFactory = new GraphApiFactory();
        return $graphApiFactory->create($tokenProvider->get());
    }

    protected function createRefreshTokenProvider(ArrayObject $state, ?array $oauthData = null): TokenProvider
    {
        $appId = (string) getenv('OAUTH_APP_ID');
        $appSecret = (string) getenv('OAUTH_APP_SECRET');
        $accessToken = (string) getenv('OAUTH_ACCESS_TOKEN');
        $refreshToken = (string) getenv('OAUTH_REFRESH_TOKEN');
        $oauthData = $oauthData ?? [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
            ];
        $dataManager = new TokenDataManager($oauthData, $state);
        return new RefreshTokenProvider($appId, $appSecret, $dataManager);
    }

    protected function checkEnvironment(array $vars): void
    {
        foreach ($vars as $var) {
            if (empty(getenv($var))) {
                throw new \Exception(sprintf('Missing environment var "%s".', $var));
            }
        }
    }
}
