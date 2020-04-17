<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter;

class Sheet implements \JsonSerializable
{
    private SheetFile $file;

    private string $id;

    private string $name;

    private bool $new;

    public function __construct(SheetFile $file, string $id, string $name, bool $new)
    {
        $this->file = $file;
        $this->id = $id;
        $this->name = $name;
        $this->new = $new;
    }

    public function getDriveId(): string
    {
        return $this->file->getDriveId();
    }

    public function getFileId(): string
    {
        return $this->file->getFileId();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isNew(): bool
    {
        return $this->new;
    }

    public function jsonSerialize(): array
    {
        return [
            'driveId' => $this->getDriveId(),
            'fileId' => $this->getFileId(),
            'worksheetId' => $this->id,
        ];
    }
}
