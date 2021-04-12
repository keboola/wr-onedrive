<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Auth;

use League\OAuth2\Client\Token\AccessTokenInterface;

interface TokenProvider
{
    public function get(): AccessTokenInterface;
}
