<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use Keboola\OneDriveWriter\Exception\ResourceNotFoundException;
use Keboola\OneDriveWriter\Exception\UnexpectedValueException;
use Keboola\OneDriveWriter\Fixtures\FixturesCatalog;
use PHPUnit\Framework\Assert;

class GetSheetIdTest extends BaseTest
{
    /**
     * @dataProvider getFiles
     */
    public function testGetSheetIdByPosition(string $file): void
    {
        $fixture = $this->fixtures->getDrive()->getFile($file);
        $worksheets = $this->api->getSheets($fixture->getDriveId(), $fixture->getFileId());
        foreach ($worksheets as $sheet) {
            Assert::assertSame(
                $sheet->getWorksheetId(),
                $this->api->getSheetIdByPosition($fixture->getDriveId(), $fixture->getFileId(), $sheet->getPosition())
            );
        }
    }

    public function testSheetNotFound(): void
    {
        $fixture = $this->fixtures->getDrive()->getFile(FixturesCatalog::FILE_ONE_SHEET);

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('No worksheet at position "123".');
        $this->api->getSheetIdByPosition($fixture->getDriveId(), $fixture->getFileId(), 123);
    }

    public function testNegativePosition(): void
    {
        $fixture = $this->fixtures->getDrive()->getFile(FixturesCatalog::FILE_ONE_SHEET);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Worksheet position must be greater than zero. Given "-5".');
        $this->api->getSheetIdByPosition($fixture->getDriveId(), $fixture->getFileId(), -5);
    }

    public function getFiles(): array
    {
        return [
            [FixturesCatalog::FILE_ONE_SHEET],
            [FixturesCatalog::FILE_HIDDEN_SHEET],
        ];
    }
}
