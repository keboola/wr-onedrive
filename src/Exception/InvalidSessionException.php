<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Exception;

use Keboola\CommonExceptions\UserExceptionInterface;

class InvalidSessionException extends \Exception implements UserExceptionInterface
{

}
