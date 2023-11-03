<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Auth;

use ArrayObject;
use Keboola\OneDriveWriter\Configuration\Config;
use Psr\Log\LoggerInterface;

class TokenProviderFactory
{
    private Config $config;

    private ArrayObject $stateObject;

    private LoggerInterface $logger;

    public function __construct(Config $config, ArrayObject $stateObject, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->stateObject = $stateObject;
        $this->logger = $logger;
    }

    public function create(): TokenProvider
    {
        // OAuth Refresh Token login
        $tokenDataManager = new TokenDataManager($this->config->getOAuthApiData(), $this->stateObject);
        return new RefreshTokenProvider(
            $this->config->getOAuthApiAppKey(),
            $this->config->getOAuthApiAppSecret(),
            $this->config->getImageParameters()['oneDriveAuthorityUrl'] ?? null,
            $tokenDataManager,
            $this->logger
        );
    }
}
