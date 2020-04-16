<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use JakubOnderka\PhpParallelLint\ArrayIterator;
use Keboola\OneDriveWriter\Fixtures\FixturesCatalog;
use Keboola\OneDriveWriter\Sheet;
use Keboola\OneDriveWriter\SheetFile;
use PHPUnit\Framework\Assert;

class InsertRowsTest extends BaseTest
{
    /**
     * @dataProvider getFiles
     */
    public function testInsertRows(
        bool $append,
        string $fileName,
        string $rangeBefore,
        array $insertRows,
        string $rangeAfter,
        array $stateAfter,
        array $expectedLogs,
        int $bulk = 30000
    ): void {
        $driveId = $this->fixtures->getDrive()->getDriveId();
        $file = $this->utils->uploadTmpFile($driveId, $fileName);

        // Content before
        $contentBefore = $this->utils->getWorksheetContent($file, 0);
        Assert::assertSame($rangeBefore, $contentBefore->getRange()->getAddress());

        // Insert
        $iterator = new ArrayIterator($insertRows);
        $this->api->insertRows(
            new Sheet(
                new SheetFile($file->getDriveId(), $file->getFileId()),
                $file->getWorksheetId(0),
                'Some name',
                false
            ),
            $append,
            $iterator,
            $bulk
        );

        // Check result
        sleep(2);
        $contentAfter = $this->utils->getWorksheetContent($file, 0);
        Assert::assertSame($rangeAfter, $contentAfter->getRange()->getAddress());
        Assert::assertSame($stateAfter, $contentAfter->getRows());

        // Check logs
        if ($expectedLogs !== null) {
            Assert::assertSame(
                $expectedLogs,
                array_map(fn(array $r) => strtoupper($r['level']) . ': ' . $r['message'], $this->logger->records)
            );
        }
    }

    public function getFiles(): array
    {
        return [
            'one-sheet-overwrite' => [
               false,
                FixturesCatalog::FILE_ONE_SHEET,
                'A1:C3',
                [['a', 'b', 'c', 'd'], ['1', '2', '3', ''], ['x', 'y', 'z', 'zz']],
                'A1:D3',
                [['a', 'b', 'c', 'd'], ['1', '2', '3', ''], ['x', 'y', 'z', 'zz']],
                [
                    'INFO: Sheet cleared.',
                    'INFO: Inserted 3 rows.',
                ],
            ],
            'one-sheet-overwrite-bulk-size-2' => [
                false,
                FixturesCatalog::FILE_ONE_SHEET,
                'A1:C3',
                [['a', 'b'], ['1', 'a'], ['2', 'b'], ['3', 'c'], ['4', 'd'], ['5', 'e'], ['6', 'f']],
                'A1:B7',
                [['a', 'b'], ['1', 'a'], ['2', 'b'], ['3', 'c'], ['4', 'd'], ['5', 'e'], ['6', 'f']],
                [
                    'INFO: Sheet cleared.',
                    'INFO: Inserted 2 rows.',
                    'INFO: Inserted 2 rows.',
                    'INFO: Inserted 2 rows.',
                    'INFO: Inserted 1 rows.',
                ],
                2,
            ],
            'one-sheet-overwrite-bulk-size-3' => [
                false,
                FixturesCatalog::FILE_ONE_SHEET,
                'A1:C3',
                [['a', 'b'], ['1', 'a'], ['2', 'b'], ['3', 'c'], ['4', 'd'], ['5', 'e'], ['6', 'f']],
                'A1:B7',
                [['a', 'b'], ['1', 'a'], ['2', 'b'], ['3', 'c'], ['4', 'd'], ['5', 'e'], ['6', 'f']],
                [
                    'INFO: Sheet cleared.',
                    'INFO: Inserted 3 rows.',
                    'INFO: Inserted 3 rows.',
                    'INFO: Inserted 1 rows.',
                ],
                3,
            ],
            'table-offset-overwrite' => [
                false,
                FixturesCatalog::FILE_TABLE_OFFSET,
                'C9:L14',
                [['a', 'b', 'c'], ['1', '2', '3'], ['x', 'y', 'z']],
                'A1:C3',
                [['a', 'b', 'c'], ['1', '2', '3'], ['x', 'y', 'z']],
                [
                    'INFO: Sheet cleared.',
                    'INFO: Inserted 3 rows.',
                ],
            ],
            'empty-overwrite' => [
                false,
                FixturesCatalog::FILE_EMPTY,
                'A1:A1',
                [['a', 'b', 'c'], ['1', '2', '3'], ['x', 'y', 'z']],
                'A1:C3',
                [['a', 'b', 'c'], ['1', '2', '3'], ['x', 'y', 'z']],
                [
                    'INFO: Sheet cleared.',
                    'INFO: Inserted 3 rows.',
                ],
            ],
            'one-sheet-append-same-header' => [
                true,
                FixturesCatalog::FILE_ONE_SHEET,
                'A1:C3',
                [['Col 1', 'Col 2', 'Col 3'], ['1', '2', '3'], ['x', 'y', 'z']],
                'A1:C5',
                [
                    // Old rows
                    ['Col 1', 'Col 2', 'Col 3'], ['A', 'B', 'C'], ['D', 'E', 'F'],
                    // New rows
                    ['1', '2', '3'], ['x', 'y', 'z'],
                ],
                [
                    'INFO: Current sheet range: "A1:C3"',
                    'INFO: Current sheet header "A1:C1": "Col_1", "Col_2", "Col_3"',
                    'INFO: Inserted 2 rows.',
                ],
            ],
            'one-sheet-append-different-header' => [
                true,
                FixturesCatalog::FILE_ONE_SHEET,
                'A1:C3',
                [['a', 'b', 'c', 'd'], ['1', '2', '3', ''], ['x', 'y', 'z', 'zz']],
                'A1:D5',
                [
                    // Old rows
                    ['Col 1', 'Col 2', 'Col 3', ''], ['A', 'B', 'C', ''], ['D', 'E', 'F', ''],
                    // New rows
                    ['1', '2', '3', ''], ['x', 'y', 'z', 'zz'],
                ],
                [
                    'INFO: Current sheet range: "A1:C3"',
                    'INFO: Current sheet header "A1:C1": "Col_1", "Col_2", "Col_3"',
                    'WARNING: Headers mismatch. Ignored new header: "a", "b", "c", "d"',
                    'INFO: Inserted 2 rows.',
                ],
            ],
            'one-sheet-append-bulk-size-2' => [
                true,
                FixturesCatalog::FILE_ONE_SHEET,
                'A1:C3',
                [['a', 'b'], ['1', 'a'], ['2', 'b'], ['3', 'c'], ['4', 'd'], ['5', 'e'], ['6', 'f'], ['7', 'g']],
                'A1:C10',
                [
                    ['Col 1', 'Col 2', 'Col 3'],
                    ['A', 'B', 'C'],
                    ['D', 'E', 'F'],
                    ['1', 'a', ''],
                    ['2', 'b', ''],
                    ['3', 'c', ''],
                    ['4', 'd', ''],
                    ['5', 'e', ''],
                    ['6', 'f', ''],
                    ['7', 'g', ''],
                ],
                [
                    'INFO: Current sheet range: "A1:C3"',
                    'INFO: Current sheet header "A1:C1": "Col_1", "Col_2", "Col_3"',
                    'WARNING: Headers mismatch. Ignored new header: "a", "b"',
                    'INFO: Inserted 2 rows.',
                    'INFO: Inserted 2 rows.',
                    'INFO: Inserted 2 rows.',
                    'INFO: Inserted 1 rows.',
                ],
                2,
            ],
            'one-sheet-append-bulk-size-3' => [
                true,
                FixturesCatalog::FILE_ONE_SHEET,
                'A1:C3',
                [['a', 'b'], ['1', 'a'], ['2', 'b'], ['3', 'c'], ['4', 'd'], ['5', 'e'], ['6', 'f'], ['7', 'g']],
                'A1:C10',
                [
                    ['Col 1', 'Col 2', 'Col 3'],
                    ['A', 'B', 'C'],
                    ['D', 'E', 'F'],
                    ['1', 'a', ''],
                    ['2', 'b', ''],
                    ['3', 'c', ''],
                    ['4', 'd', ''],
                    ['5', 'e', ''],
                    ['6', 'f', ''],
                    ['7', 'g', ''],
                ],
                [
                    'INFO: Current sheet range: "A1:C3"',
                    'INFO: Current sheet header "A1:C1": "Col_1", "Col_2", "Col_3"',
                    'WARNING: Headers mismatch. Ignored new header: "a", "b"',
                    'INFO: Inserted 3 rows.',
                    'INFO: Inserted 3 rows.',
                    'INFO: Inserted 1 rows.',
                ],
                3,
            ],
            'table-offset-append' => [
                true,
                FixturesCatalog::FILE_TABLE_OFFSET,
                'C9:L14',
                [['a', 'b', 'c', 'd'], ['1', '2', '3', ''], ['x', 'y', 'z', 'zz']],
                'C9:L16',
                [
                    // Old rows
                    [
                        'Segment',
                        '',
                        'Country',
                        'Duplicate',
                        'Duplicate',
                        'Product',
                        'Discount Band',
                        'Units Sold',
                        '',
                        '',
                    ],
                    ['Government', '', 'Canada', '', '6', 'Carretera', 'None', '1618.5', '', '',],
                    ['Government', '', 'Germany', '', '7', 'Carretera', 'None', '1321', '', '',],
                    ['Midmarket', '', 'France', '', '8', 'Carretera', 'None', '2178', '', 'x',],
                    ['Midmarket', '', 'Germany', '', '9', 'Carretera', 'None', '888', '', 'y',],
                    [
                        'Midmarket', '(empty header)', 'Mexico', '(duplicate header)',
                        '(duplicate header)', 'Carretera', 'None', '2470', '', 'z',
                    ],
                    // New rows
                    ['1', '2', '3', '', '', '', '', '', '', ''],
                    ['x', 'y', 'z', 'zz', '', '', '', '', '', ''],
                ],
                [
                    'INFO: Current sheet range: "C9:L14"',
                    'INFO: Current sheet header "C9:L9": "Segment", "column-2", "Country", "Duplicate", ' .
                    '"Duplicate-1", "Product", "Discount_Band", "Units_Sold", "column-9", "column-10"',
                    'WARNING: Headers mismatch. Ignored new header: "a", "b", "c", "d"',
                    'INFO: Inserted 2 rows.',
                ],
            ],
            'empty-append' => [
                true,
                FixturesCatalog::FILE_EMPTY,
                'A1:A1',
                [['a', 'b', 'c'], ['1', '2', '3'], ['x', 'y', 'z']],
                'A1:C3',
                [['a', 'b', 'c'], ['1', '2', '3'], ['x', 'y', 'z']],
                [
                    'INFO: Sheet is empty.',
                    'INFO: Inserted 3 rows.',
                ],
            ],
        ];
    }
}
