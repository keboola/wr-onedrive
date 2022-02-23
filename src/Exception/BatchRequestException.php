<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Exception;

use Exception;
use Keboola\CommonExceptions\ApplicationExceptionInterface;
use Throwable;

class BatchRequestException extends Exception implements ApplicationExceptionInterface
{
    private ?string $errorCode;

    public function __construct(string $message, int $code, ?Throwable $previous = null, ?string $errorCode = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
