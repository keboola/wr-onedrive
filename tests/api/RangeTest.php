<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use Keboola\OneDriveWriter\Fixtures\FixturesCatalog;
use Keboola\OneDriveWriter\Sheet;
use Keboola\OneDriveWriter\SheetFile;
use PHPUnit\Framework\Assert;

class RangeTest extends BaseTest
{
    /**
     * @dataProvider getFiles
     */
    public function testGetWorksheetRange(string $file, int $worksheetPosition, string $expectedRange): void
    {
        $fixture = $this->fixtures->getDrive()->getFile($file);
        $range = $this->api->getSheetRange(
            new Sheet(
                new SheetFile($fixture->getDriveId(), $fixture->getFileId(), false),
                $fixture->getWorksheetId($worksheetPosition),
                'Some name',
                false,
            )
        );
        Assert::assertSame($expectedRange, $range->getAddress());
    }

    public function getFiles(): array
    {
        return [
            'hidden-sheet' => [
                FixturesCatalog::FILE_HIDDEN_SHEET,
                2, // hidden sheet, see GetSheetsTest.php
                'A48:C50',
            ],
            'one-sheet' => [
                FixturesCatalog::FILE_ONE_SHEET,
                0,
                'A1:C3',
            ],
            'only-header' => [
                FixturesCatalog::FILE_ONLY_HEADER,
                0,
                'A1:D1',
            ],
            'only-one-column' => [
                FixturesCatalog::FILE_ONLY_ONE_COLUMN,
                0,
                'A1:A4',
            ],
            'special-cases' => [
                FixturesCatalog::FILE_SPECIAL_CASES,
                0,
                'C4:I14',
            ],
            'table-offset' => [
                FixturesCatalog::FILE_TABLE_OFFSET,
                0,
                'C9:L14',
            ],
        ];
    }
}
