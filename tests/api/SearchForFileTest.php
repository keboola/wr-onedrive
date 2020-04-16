<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use Keboola\OneDriveWriter\Api\Model\File;
use Keboola\OneDriveWriter\Exception\FileInDriveNotFoundException;
use Keboola\OneDriveWriter\Exception\InvalidFileTypeException;
use Keboola\OneDriveWriter\Exception\ShareLinkException;
use Keboola\OneDriveWriter\Exception\UnexpectedValueException;
use Keboola\OneDriveWriter\Fixtures\FixturesCatalog;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedTestError;

class SearchForFileTest extends BaseTest
{
    public function testEmptySearch(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unexpected path format "".');
        $this->api->searchWorkbook('');
    }

    public function testSearchByPathInMeDrive1(): void
    {
        $fixture = $this->fixtures->getMeDrive()->getFile(FixturesCatalog::FILE_ONE_SHEET);
        $path = $fixture->getPath();
        Assert::assertSame('/', $path[0]); // path starts with /

        /** @var File $file */
        $file = $this->api->searchWorkbook($path);
        Assert::assertNotNull($file);
        Assert::assertSame('one_sheet.xlsx', $file->getName());
        Assert::assertSame(['my', '__wr-onedrive-test-folder', 'valid'], $file->getPath());
        Assert::assertSame($fixture->getDriveId(), $file->getDriveId());
        Assert::assertSame($fixture->getFileId(), $file->getFileId());
    }

    public function testSearchByPathInMeDrive2(): void
    {
        $fixture = $this->fixtures->getMeDrive()->getFile(FixturesCatalog::FILE_ONE_SHEET);
        $path = $fixture->getPath();
        $path = ltrim($path, '/');
        Assert::assertNotSame('/', $path[0]); // path NOT starts with /

        /** @var File $file */
        $file = $this->api->searchWorkbook($path);
        Assert::assertNotNull($file);
        Assert::assertSame('one_sheet.xlsx', $file->getName());
        Assert::assertSame(['my', '__wr-onedrive-test-folder', 'valid'], $file->getPath());
        Assert::assertSame($fixture->getDriveId(), $file->getDriveId());
        Assert::assertSame($fixture->getFileId(), $file->getFileId());
    }

    public function testSearchByPathInMeDriveNotFound(): void
    {
        $this->expectException(FileInDriveNotFoundException::class);
        $this->api->searchWorkbook('/file/not/found');
    }

    public function testSearchByPathInMeDriveInvalidFileType(): void
    {
        $this->expectException(InvalidFileTypeException::class);
        $this->expectExceptionMessage(
            'File is not in the "XLSX" Excel format. Mime type: "application/vnd.oasis.opendocument.text"'
        );
        $this->api->searchWorkbook(
            $this->fixtures->getMeDrive()->getFile(FixturesCatalog::FILE_ODT)->getPath()
        );
    }

    public function testSearchByPathInDrive(): void
    {
        $drive = $this->fixtures->getMeDrive();
        $driveId = urlencode($drive->getDriveId());
        $fixture = $drive->getFile(FixturesCatalog::FILE_ONE_SHEET);

        /** @var File $file */
        $file = $this->api->searchWorkbook("drive://{$driveId}/{$fixture->getPath()}");
        Assert::assertNotNull($file);
        Assert::assertSame($fixture->getDriveId(), $file->getDriveId());
        Assert::assertSame($fixture->getFileId(), $file->getFileId());
        Assert::assertSame($fixture->getName(), $file->getName());
        Assert::assertSame(
            $fixture->getPath(),
            '/' .implode('/', $file->getPath()) . '/' . $file->getName()
        );
    }

    public function testSearchByPathInDriveNotFound(): void
    {
        $this->expectException(FileInDriveNotFoundException::class);
        $drive = $this->fixtures->getMeDrive();
        $driveId = urlencode($drive->getDriveId());
        $this->api->searchWorkbook("drive://{$driveId}/file/not/found");
    }

    public function testSearchByPathInDriveInvalidFileType(): void
    {
        $drive = $this->fixtures->getMeDrive();
        $driveId = urlencode($drive->getDriveId());
        $fixture = $drive->getFile(FixturesCatalog::FILE_ODT);
        $path = "drive://{$driveId}/{$fixture->getPath()}";

        $this->expectException(InvalidFileTypeException::class);
        $this->expectExceptionMessage(
            'File is not in the "XLSX" Excel format. Mime type: "application/vnd.oasis.opendocument.text"'
        );
        $this->api->searchWorkbook($path);
    }

    public function testSearchByPathInSite(): void
    {
        $sharePointDrive = $this->fixtures->getSharePointDrive();
        $sharePointSiteName = $this->fixtures->getSharePointSiteName();
        if (!$sharePointDrive || !$sharePointSiteName) {
            throw new SkippedTestError('Skipped: share point drive is not set');
        }

        $siteName = urlencode($sharePointSiteName);
        $fixture = $sharePointDrive->getFile(FixturesCatalog::FILE_ONE_SHEET);

        /** @var File $file */
        $file = $this->api->searchWorkbook("site://{$siteName}/{$fixture->getPath()}");
        Assert::assertNotNull($file);
        Assert::assertSame($fixture->getDriveId(), $file->getDriveId());
        Assert::assertSame($fixture->getFileId(), $file->getFileId());
        Assert::assertSame($fixture->getName(), $file->getName());
        Assert::assertSame(
            "sites/{$sharePointSiteName}" . $fixture->getPath(),
            implode('/', $file->getPath()) . '/' . $file->getName()
        );
    }

    public function testSearchByPathInSiteNotFound(): void
    {
        $sharePointDrive = $this->fixtures->getSharePointDrive();
        if (!$sharePointDrive) {
            throw new SkippedTestError('Skipped: share point drive is not set');
        }

        $this->expectException(FileInDriveNotFoundException::class);
        $siteName = $this->fixtures->getSharePointSiteName();
        $this->api->searchWorkbook("site://{$siteName}/file/not/found");
    }

    public function testSearchByPathInSiteInvalidFileType(): void
    {
        $sharePointDrive = $this->fixtures->getSharePointDrive();
        if (!$sharePointDrive) {
            throw new SkippedTestError('Skipped: share point drive is not set');
        }

        $siteName = $this->fixtures->getSharePointSiteName();
        $fixture = $sharePointDrive->getFile(FixturesCatalog::FILE_ODT);
        $path = "site://{$siteName}/{$fixture->getPath()}";

        $this->expectException(InvalidFileTypeException::class);
        $this->expectExceptionMessage(
            'File is not in the "XLSX" Excel format. Mime type: "application/vnd.oasis.opendocument.text"'
        );
        $this->api->searchWorkbook($path);
    }

    public function testSearchByUrlMeDrive(): void
    {
        $fixture = $this->fixtures->getMeDrive()->getFile(FixturesCatalog::FILE_ONE_SHEET);

        /** @var File $file */
        $file = $this->api->searchWorkbook($fixture->getSharingLink());
        Assert::assertNotNull($file);
        Assert::assertSame('one_sheet.xlsx', $file->getName());
    }

    public function testSearchByUrlSharePoint(): void
    {
        $sharePointDrive = $this->fixtures->getSharePointDrive();
        if (!$sharePointDrive) {
            throw new SkippedTestError('Skipped: share point drive is not set');
        }

        $fixture = $this->fixtures->getMeDrive()->getFile(FixturesCatalog::FILE_ONE_SHEET);

        /** @var File $file */
        $file = $this->api->searchWorkbook($fixture->getSharingLink());
        Assert::assertNotNull($file);
        Assert::assertSame('one_sheet.xlsx', $file->getName());
    }

    public function testSearchByUrlInvalidFileTypeMeDrive(): void
    {
        $sharePointDrive = $this->fixtures->getSharePointDrive();
        if (!$sharePointDrive) {
            throw new SkippedTestError('Skipped: share point drive is not set');
        }

        $this->expectException(InvalidFileTypeException::class);
        $this->expectExceptionMessage(
            'File is not in the "XLSX" Excel format. Mime type: "application/vnd.oasis.opendocument.text"'
        );
        $fixture = $sharePointDrive->getFile(FixturesCatalog::FILE_ODT);
        $this->api->searchWorkbook($fixture->getSharingLink());
    }

    public function testSearchByUrlInvalidFileTypeSharePoint(): void
    {
        $sharePointDrive = $this->fixtures->getSharePointDrive();
        if (!$sharePointDrive) {
            throw new SkippedTestError('Skipped: share point drive is not set');
        }

        $this->expectException(InvalidFileTypeException::class);
        $this->expectExceptionMessage(
            'File is not in the "XLSX" Excel format. Mime type: "application/vnd.oasis.opendocument.text"'
        );
        $fixture = $sharePointDrive->getFile(FixturesCatalog::FILE_ODT);
        $this->api->searchWorkbook($fixture->getSharingLink());
    }

    public function testSearchByInvalidUrl(): void
    {
        $notExistsUrl = 'https://keboolads.sharepoint.com/:x:/r/sites/KeboolaExtraction/Excel/invalid';
        $this->expectException(ShareLinkException::class);
        $this->expectExceptionMessageMatches(
            '~The sharing link ".*" no exists, or you do not have permission to access it\.~',
        );
        $this->api->searchWorkbook($notExistsUrl);
    }
}
