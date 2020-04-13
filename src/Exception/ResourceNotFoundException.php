<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Exception;

use Keboola\CommonExceptions\UserExceptionInterface;

class ResourceNotFoundException extends \Exception implements UserExceptionInterface
{

}
