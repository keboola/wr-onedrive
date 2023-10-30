<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Auth;

use Keboola\OneDriveWriter\Exception\AccessTokenRefreshException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use LogicException;
use Psr\Log\LoggerInterface;

class RefreshTokenProvider implements TokenProvider
{
    private const AUTHORITY_URL = 'https://login.microsoftonline.com/common';
    private const AUTHORIZE_ENDPOINT = '/oauth2/v2.0/authorize';
    private const TOKEN_ENDPOINT = '/oauth2/v2.0/token';
    private const SCOPES = ['offline_access', 'User.Read', 'Files.ReadWrite.All', 'Sites.ReadWrite.All'];

    private string $appId;

    private string $appSecret;

    private TokenDataManager $dataManager;

    private LoggerInterface $logger;

    public function __construct(
        string $appId,
        string $appSecret,
        TokenDataManager $dataManager,
        LoggerInterface $logger
    ) {
        $this->appId = $appId;
        $this->appSecret = $appSecret;
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
                    $newToken = $provider->getAccessToken(
                        'refresh_token',
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

    private function createOAuthProvider(string $appId, string $appSecret): GenericProvider
    {
        return new GenericProvider([
            'clientId' => $appId,
            'clientSecret' => $appSecret,
            'urlAuthorize' => self::AUTHORITY_URL . self::AUTHORIZE_ENDPOINT,
            'urlAccessToken' => self::AUTHORITY_URL . self::TOKEN_ENDPOINT,
            'urlResourceOwnerDetails' => '',
            'scopes' => implode(' ', self::SCOPES),
        ]);
    }
}
