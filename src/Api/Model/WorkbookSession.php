<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api\Model;

class WorkbookSession
{
    private string $driveId;

    private string $fileId;

    private string $sessionId;

    public function __construct(string $driveId, string $fileId, string $sessionId)
    {
        $this->driveId = $driveId;
        $this->fileId = $fileId;
        $this->sessionId = $sessionId;
    }

    public function getDriveId(): string
    {
        return $this->driveId;
    }

    public function getFileId(): string
    {
        return $this->fileId;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }
}
