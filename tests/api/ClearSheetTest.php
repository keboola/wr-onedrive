<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use Keboola\OneDriveWriter\Fixtures\FixturesCatalog;
use Keboola\OneDriveWriter\Sheet;
use Keboola\OneDriveWriter\SheetFile;
use PHPUnit\Framework\Assert;

class ClearSheetTest extends BaseTest
{
    public function testClearSheet(): void
    {
        $driveId = $this->fixtures->getDrive()->getDriveId();
        $file = $this->utils->uploadTmpFile($driveId, FixturesCatalog::FILE_SPECIAL_CASES);

        // Not empty content before
        $content = $this->utils->getWorksheetContent($file, 0);
        Assert::assertFalse($content->isEmpty());
        Assert::assertSame('C4:I14', $content->getRange()->getAddress());

        // Clear
        $this->api->clearSheet(new Sheet(
            new SheetFile($file->getDriveId(), $file->getFileId(), false),
            $file->getWorksheetId(0),
            'Some name',
            false
        ));

        // Empty content after
        sleep(1);
        $content = $this->utils->getWorksheetContent($file, 0);
        Assert::assertTrue($content->isEmpty());
        Assert::assertSame('A1:A1', $content->getRange()->getAddress());
    }
}
