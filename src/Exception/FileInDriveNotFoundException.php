<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Exception;

use Throwable;

class FileInDriveNotFoundException extends ResourceNotFoundException
{
    private string $endpointUrl;

    public function __construct(string $message, string $endpointUrl, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->endpointUrl = $endpointUrl;
    }

    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }
}
