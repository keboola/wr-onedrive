<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter;

class SheetFile implements \JsonSerializable
{
    private string $driveId;

    private string $fileId;

    private bool $new;

    public function __construct(string $driveId, string $fileId, bool $new)
    {
        $this->driveId = $driveId;
        $this->fileId = $fileId;
        $this->new = $new;
    }

    public function getDriveId(): string
    {
        return $this->driveId;
    }

    public function getFileId(): string
    {
        return $this->fileId;
    }

    public function isNew(): bool
    {
        return $this->new;
    }

    public function jsonSerialize(): array
    {
        return [
            'driveId' => $this->driveId,
            'fileId' => $this->fileId,
        ];
    }
}
