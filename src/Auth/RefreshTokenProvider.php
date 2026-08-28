<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Auth;

use GuzzleHttp\Exception\ConnectException;
use Keboola\OneDriveWriter\Exception\AccessTokenRefreshException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Retry\BackOff\BackOffPolicyInterface;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;

class RefreshTokenProvider implements TokenProvider
{
    private const AUTHORITY_URL = 'https://login.microsoftonline.com/common';
    private const AUTHORIZE_ENDPOINT = '/oauth2/v2.0/authorize';
    private const TOKEN_ENDPOINT = '/oauth2/v2.0/token';
    private const SCOPES = ['offline_access', 'User.Read', 'Files.ReadWrite.All', 'Sites.ReadWrite.All'];

    // The login endpoint sometimes drops the connection (eg. "cURL error 35 ... reset by peer").
    // Such failures are transient, so the request is retried before the job is failed.
    // Every Microsoft Graph call already retries a ConnectException (see Api::executeWithRetry),
    // this refresh was the only HTTP call in the component that did not.
    // The token is refreshed from the Component constructor, so this runs for sync actions too,
    // and the number of attempts is therefore kept low on purpose: at most 1s + 2s of waiting
    // before the job fails. Same values as keboola/ex-onedrive, so both components behave alike.
    private const RETRY_MAX_ATTEMPTS = 3; // includes the initial try
    private const RETRY_INITIAL_INTERVAL = 1000; // ms, doubled on each attempt
    private const RETRY_EXCEPTIONS = [ConnectException::class];

    private string $appId;

    private string $appSecret;

    private TokenDataManager $dataManager;

    private LoggerInterface $logger;

    private string $authorityUrl;

    public function __construct(
        string $appId,
        string $appSecret,
        ?string $authorityUrl,
        TokenDataManager $dataManager,
        LoggerInterface $logger
    ) {
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->authorityUrl = $authorityUrl ?? self::AUTHORITY_URL;
        $this->dataManager = $dataManager;
        $this->logger = $logger;
    }

    public function get(): AccessTokenInterface
    {
        $provider = $this->createOAuthProvider($this->appId, $this->appSecret);
        $tokens = $this->dataManager->load();

        // It is needed to always refresh token, because original token expires after 1 hour
        $newToken = null;

        // Try token from stored state, and from the configuration.
        if (!$tokens->valid()) {
            throw new AccessTokenRefreshException(
                'Missing token in configuration or state file.'
            );
        } else {
            while ($tokens->valid()) {
                try {
                    $newToken = $this->refreshToken(
                        $provider,
                        ['refresh_token' => $tokens->current()->getRefreshToken()]
                    );
                    break;
                } catch (IdentityProviderException $e) {
                    $tokens->next();
                    /** @var array<string, string> $responseBody */
                    $responseBody = $e->getResponseBody();
                    if ($tokens->valid()) {
                        $this->logger->info(sprintf(
                            'Microsoft OAuth API token refresh failed (%s: %s), trying next token.',
                            $e->getMessage(),
                            $responseBody['error_description'] ?? 'No error description'
                        ));
                    } else {
                        throw new AccessTokenRefreshException(
                            sprintf(
                                'Microsoft OAuth API token refresh failed (%s: %s). Please reset authorization in ' .
                                'the extractor configuration.',
                                $e->getMessage(),
                                $responseBody['error_description'] ?? 'No error description'
                            )
                        );
                    }
                }
            }
        }

        if ($newToken === null) {
            throw new LogicException('Token is null.');
        }

        $this->dataManager->store($newToken);
        return $newToken;
    }

    protected function createOAuthProvider(string $appId, string $appSecret): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $appId,
            'clientSecret' => $appSecret,
            'urlAuthorize' => $this->authorityUrl . self::AUTHORIZE_ENDPOINT,
            'urlAccessToken' => $this->authorityUrl . self::TOKEN_ENDPOINT,
            'urlResourceOwnerDetails' => '',
            'scopes' => implode(' ', self::SCOPES),
        ]);
    }

    /**
     * Separate factory, so tests can replace the back-off with a no-op one.
     */
    protected function createBackOffPolicy(): BackOffPolicyInterface
    {
        return new ExponentialBackOffPolicy(self::RETRY_INITIAL_INTERVAL);
    }

    /**
     * Refreshes the token, retrying only connection level failures.
     *
     * An invalid/expired token still fails on the first try (IdentityProviderException is not
     * retried, so the next stored token is tried immediately, exactly as before) and a persistent
     * connection problem is re-thrown after the last attempt, so a failing refresh keeps failing
     * the job. Nothing is turned into a success and no error message changes.
     *
     * Only ConnectException is retried, on purpose. It means the connection was never
     * established, so the request provably never reached the server and it is safe to send
     * again. Please do not extend the list with RequestException: a request that failed while
     * the response was already in flight may have been processed, and because Microsoft rotates
     * refresh tokens, replaying it would come back as "invalid_grant" and the user would be
     * told to reset an authorization which is in fact still valid.
     *
     * @param array<string, string> $options
     */
    private function refreshToken(GenericProvider $provider, array $options): AccessTokenInterface
    {
        $retryProxy = new RetryProxy(
            new SimpleRetryPolicy(self::RETRY_MAX_ATTEMPTS, self::RETRY_EXCEPTIONS),
            $this->createBackOffPolicy(),
            $this->logger
        );

        return $retryProxy->call(function () use ($provider, $options): AccessTokenInterface {
            return $provider->getAccessToken('refresh_token', $options);
        });
    }
}
