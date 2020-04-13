<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Exception;

use Keboola\CommonExceptions\UserExceptionInterface;

class BadRequestException extends \Exception implements UserExceptionInterface
{

}
