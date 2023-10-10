<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Fixtures;

use ArrayObject;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Keboola\OneDriveWriter\Api\Api;
use Keboola\OneDriveWriter\Api\GraphApiFactory;
use Keboola\OneDriveWriter\Api\Helpers;
use Keboola\OneDriveWriter\Auth\RefreshTokenProvider;
use Keboola\OneDriveWriter\Auth\TokenDataManager;
use Keboola\OneDriveWriter\Exception\BatchRequestException;
use Psr\Log\NullLogger;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\CallableRetryPolicy;
use Retry\RetryProxy;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Http\GraphResponse;

class FixturesApi
{
    private const RETRY_HTTP_CODES = Api::RETRY_HTTP_CODES + [401, 404];

    private Graph $graphApi;

    public function __construct()
    {
        $this->graphApi = $this->createGraphApi();
    }

    public function getGraph(): Graph
    {
        return $this->graphApi;
    }

    public function get(string $uri, array $params = []): GraphResponse
    {
        return $this->executeWithRetry('GET', $uri, $params);
    }

    public function post(string $uri, array $params = [], array $body = []): GraphResponse
    {
        return $this->executeWithRetry('POST', $uri, $params, $body);
    }

    public function delete(string $uri, array $params = []): GraphResponse
    {
        return $this->executeWithRetry('DELETE', $uri, $params);
    }

    public function executeWithRetry(string $method, string $uri, array $params = [], array $body = []): GraphResponse
    {
        $proxy = $this->createRetryProxy();
        return $proxy->call(function () use ($method, $uri, $params, $body) {
            return $this->execute($method, $uri, $params, $body);
        });
    }

    public function createRetryProxy(): RetryProxy
    {
        $backOffPolicy = new ExponentialBackOffPolicy(500, 2.0, 5000);
        $retryPolicy = new CallableRetryPolicy(function (\Throwable $e) {
            // Retry on connect exception, eg. Could not resolve host: login.microsoftonline.com
            if ($e instanceof ConnectException) {
                return true;
            }

            if ($e instanceof RequestException || $e instanceof BatchRequestException) {
                // Retry only on defined HTTP codes
                if (in_array($e->getCode(), self::RETRY_HTTP_CODES, true)) {
                    return true;
                }

                // Retry if communication problems
                if (strpos($e->getMessage(), 'There were communication or server problems') !== false) {
                    return true;
                }

                // Retry if resource modified
                if (strpos($e->getMessage(), 'The resource has changed') !== false) {
                    return true;
                }
            }

            return false;
        }, Api::RETRY_MAX_ATTEMPTS);

        return new RetryProxy($retryPolicy, $backOffPolicy);
    }

    public function execute(string $method, string $uri, array $params = [], array $body = []): GraphResponse
    {
        $uri = Helpers::replaceParamsInUri($uri, $params);
        $request = $this->graphApi->createRequest($method, $uri);
        if ($body) {
            $request->attachBody($body);
        }

        try {
            return $request->execute();
        } catch (RequestException $e) {
            throw Helpers::processRequestException($e);
        }
    }

    public function pathToUrl(string $driveId, string $path): string
    {
        $driveId = urlencode($driveId);
        $path = Helpers::convertPathToApiFormat($path);
        return "/drives/{$driveId}/root{$path}";
    }

    private function createGraphApi(): Graph
    {
        $appId = (string) getenv('OAUTH_APP_ID');
        $appSecret = (string) getenv('OAUTH_APP_SECRET');
        $accessToken = (string) getenv('OAUTH_ACCESS_TOKEN');
        $refreshToken = (string) getenv('OAUTH_REFRESH_TOKEN');
        $oauthData = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
        $dataManager = new TokenDataManager($oauthData, new ArrayObject());
        $tokenProvider = new RefreshTokenProvider($appId, $appSecret, $dataManager, new NullLogger());
        $apiFactory = new GraphApiFactory();
        return $apiFactory->create($tokenProvider->get());
    }
}
