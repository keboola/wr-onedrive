<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use Keboola\OneDriveWriter\Fixtures\FixturesCatalog;
use Keboola\OneDriveWriter\Sheet;
use Keboola\OneDriveWriter\SheetFile;
use PHPUnit\Framework\Assert;

class HeaderTest extends BaseTest
{
    /**
     * @dataProvider getFiles
     */
    public function testGetSheetHeader(
        string $file,
        int $worksheetPosition,
        string $expectedAddress,
        array $expectedColumns
    ): void {
        $fixture = $this->fixtures->getDrive()->getFile($file);
        $header = $this->api->getSheetHeader(new Sheet(
            new SheetFile($fixture->getDriveId(), $fixture->getFileId()),
            $fixture->getWorksheetId($worksheetPosition),
            'Some name',
            false
        ));
        Assert::assertSame($expectedAddress, $header->getAddress());
        Assert::assertSame($expectedColumns, $header->getColumns());
    }

    public function getFiles(): array
    {
        return [
            'hidden-sheet' => [
                FixturesCatalog::FILE_HIDDEN_SHEET,
                2, // hidden sheet, see ListSheetsTest.php
                'A48:C48',
                ['Col_4', 'Col_5', 'Col_6'],
            ],
            'one-sheet' => [
                FixturesCatalog::FILE_ONE_SHEET,
                0,
                'A1:C1',
                ['Col_1', 'Col_2', 'Col_3'],
            ],
            'only-header' => [
                FixturesCatalog::FILE_ONLY_HEADER,
                0,
                'A1:D1',
                ['Col1', 'Col2', 'Col3', 'Col4'],
            ],
            'only-one-column' => [
                FixturesCatalog::FILE_ONLY_ONE_COLUMN,
                0,
                'A1:A1',
                ['Col1'],
            ],
            'special-cases' => [
                FixturesCatalog::FILE_SPECIAL_CASES,
                0,
                'C4:I4',
                [
                    'Duplicate',
                    'Duplicate-1',
                    'column-3',
                    'column-4',
                    'Special_123_uescr',
                    'column-6',
                    'column-7',
                ],
            ],
            'table-offset' => [
                FixturesCatalog::FILE_TABLE_OFFSET,
                0,
                'C9:L9',
                [
                    'Segment',
                    'column-2',
                    'Country',
                    'Duplicate',
                    'Duplicate-1',
                    'Product',
                    'Discount_Band',
                    'Units_Sold',
                    'column-9',
                    'column-10',
                ],
            ],
        ];
    }
}
