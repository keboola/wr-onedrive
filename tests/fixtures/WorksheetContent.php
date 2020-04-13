<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Fixtures;

use Keboola\OneDriveWriter\Api\Model\TableRange;

class WorksheetContent
{
    private TableRange $range;

    private array $rows;

    public function __construct(string $address, array $rows)
    {
        $this->range = TableRange::from($address);
        $this->rows = $rows;
    }

    public function getRange(): TableRange
    {
        return $this->range;
    }

    public function getRows(): array
    {
        return $this->rows;
    }

    public function isEmpty(): bool
    {
        return empty($this->rows);
    }
}
