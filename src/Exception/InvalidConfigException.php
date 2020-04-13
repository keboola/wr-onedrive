<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Exception;

use Keboola\CommonExceptions\UserExceptionInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class InvalidConfigException extends InvalidConfigurationException implements UserExceptionInterface
{

}
