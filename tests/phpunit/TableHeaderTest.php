<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests;

use Keboola\OneDriveWriter\Api\Model\TableHeader;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class TableHeaderTest extends TestCase
{
    public function testGetters(): void
    {
        $row = TableHeader::from('Sheet1!B123:I456', ['a', 'b', 'b', 'c']);
        Assert::assertSame('B', $row->getStartColumn());
        Assert::assertSame('B123', $row->getStartCell());
        Assert::assertSame('I', $row->getEndColumn());
        Assert::assertSame('I123', $row->getEndCell());
        Assert::assertSame(123, $row->getFirstRowNumber());
        Assert::assertSame(123, $row->getLastRowNumber());
        Assert::assertSame(['a', 'b', 'b-1', 'c'], $row->getColumns());
    }

    /**
     * @dataProvider getColumns
     */
    public function testParseColumns(array $input, array $expected): void
    {
        Assert::assertSame($expected, TableHeader::parseColumns($input));
    }

    public function getColumns(): array
    {
        return [
            [
                [],
                [],
            ],
            [
                ['', 'b', ''],
                ['column-1', 'b', 'column-3'],
            ],
            [
                ['', 'column-1', '', 'column-3', 'column-1', 'column-3'],
                ['column-1', 'column-1-1', 'column-3', 'column-3-1', 'column-1-2', 'column-3-2'],
            ],
            [
                ['a', 'b', 'c'],
                ['a', 'b', 'c'],
            ],
            [
                ['!@#', 'úěš', '指事字'],
                ['column-1', 'ues', 'column-3'],
            ],
            [
                ['col1', 'col1', 'col1'],
                ['col1', 'col1-1', 'col1-2'],
            ],
        ];
    }
}
