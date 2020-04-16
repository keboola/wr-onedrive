<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api\Model;

use InvalidArgumentException;

class TableRange
{
    private string $startColumn;

    private string $endColumn;

    private int $firstRowNumber;

    private int $lastRowNumber;

    public static function from(string $address): self
    {
        [$start, $end, $firstRowNumber, $lastRowNumber] = self::parseStartEnd($address);
        return new self($start, $end, $firstRowNumber, $lastRowNumber);
    }

    public static function parseStartEnd(string $address): array
    {
        // Eg. address = Sheet1!B123:I456 => start=B, end=I, rows=123-456
        // ... or eg. A1 if empty file
        preg_match('~!?([A-Z]+)([0-9]+)?(?::([A-Z]+)([0-9]+)?)?$~', $address, $m);
        if (empty($m)) {
            throw new InvalidArgumentException(sprintf('Unexpected input: "%s"', $address));
        }

        $start = $m[1];
        $firstRowNumber = (int) $m[2];
        $end = $m[3] ?? $start;
        $lastRowNumber = (int) ($m[4] ?? $m[2]);

        return [$start, $end, $firstRowNumber, $lastRowNumber];
    }

    public function __construct(string $start, string $end, int $firstRowNumber, int $lastRowNumber)
    {
        $this->startColumn = $start;
        $this->endColumn = $end;
        $this->firstRowNumber = $firstRowNumber;
        $this->lastRowNumber = $lastRowNumber;
    }

    public function getStartColumn(): string
    {
        return $this->startColumn;
    }

    public function getStartCell(): string
    {
        return $this->startColumn . $this->firstRowNumber;
    }

    public function getEndColumn(): string
    {
        return $this->endColumn;
    }

    public function getEndCell(): string
    {
        return $this->endColumn . $this->lastRowNumber;
    }

    public function getAddress(): string
    {
        return $this->getStartCell() . ':' . $this->getEndCell();
    }

    public function getFirstRowNumber(): int
    {
        return $this->firstRowNumber;
    }

    public function getLastRowNumber(): int
    {
        return $this->lastRowNumber;
    }

    public function isEmpty(): bool
    {
        return
            $this->startColumn === $this->endColumn &&
            $this->firstRowNumber === $this->lastRowNumber;
    }
}
