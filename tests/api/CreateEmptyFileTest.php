<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use Keboola\OneDriveWriter\Api\Helpers;
use Keboola\OneDriveWriter\Api\Model\Worksheet;
use Keboola\OneDriveWriter\Fixtures\FixturesUtils;
use PHPUnit\Framework\Assert;

class CreateEmptyFileTest extends BaseTest
{
    public function testCreateEmptyFile(): void
    {
        [$dir, $name] = FixturesUtils::createTmpFilePath();
        $path = $dir . '/' . $name;
        $endpoint = '/me/drive/root' . Helpers::convertPathToApiFormat($path);

        $file = $this->api->createEmptyWorkbook($endpoint);
        Assert::assertSame($this->fixtures->getMeDriveId(), $file->getDriveId());
        Assert::assertNotEmpty($file->getFileId());
        Assert::assertSame($dir, '/' . implode('/', $file->getPath()));
        Assert::assertSame($name, $file->getName());

        /** @var Worksheet[] $sheets */
        $sheets = iterator_to_array($this->api->getSheets($file->getDriveId(), $file->getFileId()));
        Assert::assertCount(1, $sheets);
        Assert::assertSame('New', $sheets[0]->getName());
    }
}
