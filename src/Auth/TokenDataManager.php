<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Auth;

use ArrayObject;
use Generator;
use Keboola\Component\JsonHelper;
use Keboola\OneDriveWriter\Exception\AccessTokenInitException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

class TokenDataManager
{
    public const STATE_AUTH_DATA_KEY = '#refreshed_auth_data'; // # -> must be encrypted!

    private array $configAuthData;

    private ArrayObject $state;

    public function __construct(array $configAuthData, ArrayObject $state)
    {
        $this->configAuthData = $configAuthData;
        $this->state = $state;

        // Check required keys
        $missingKeys = array_diff(['access_token', 'refresh_token'], array_keys($this->configAuthData));
        if ($missingKeys) {
            throw new AccessTokenInitException(
                sprintf('Missing key "%s" in OAuth data array.', implode('", "', $missingKeys))
            );
        }
    }

    public function load(): Generator
    {
        // Load tokens from state.json
        $authDataJson = $this->state[self::STATE_AUTH_DATA_KEY] ?? null;
        if (is_string($authDataJson)) {
            yield new AccessToken(JsonHelper::decode($authDataJson));
        }

        // Or use default from the configuration
        yield new AccessToken($this->configAuthData);
    }

    public function store(AccessTokenInterface $newToken): void
    {
        // See AccessToken::jsonSerialize
        $this->state[self::STATE_AUTH_DATA_KEY] = json_encode($newToken);
    }
}
