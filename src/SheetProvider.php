<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter;

use Keboola\OneDriveWriter\Api\Api;
use Keboola\OneDriveWriter\Configuration\Config;
use Keboola\OneDriveWriter\Exception\FileInDriveNotFoundException;
use Keboola\OneDriveWriter\Exception\InvalidConfigException;
use Keboola\OneDriveWriter\Exception\ResourceNotFoundException;
use Keboola\OneDriveWriter\Exception\ShareLinkException;
use Keboola\OneDriveWriter\Exception\WorkbookAlreadyExistsException;

class SheetProvider
{
    private Api $api;

    private Config $config;

    public function __construct(Api $api, Config $config)
    {
        $this->api = $api;
        $this->config = $config;
    }

    public function getSheet(): Sheet
    {
        $config = $this->config;
        $workbook = $this->getFile();

        try {
            if ($config->hasWorksheetId()) {
                return $this->getSheetById($workbook, $this->config->getWorksheetId());
            } elseif ($config->hasWorksheetPosition()) {
                return $this->getSheetByPosition($workbook, $this->config->getWorksheetPosition());
            } else {
                return $this->getSheetByName($workbook, $config->getWorksheetName());
            }
        } catch (ResourceNotFoundException $e) {
            throw new ResourceNotFoundException('Worksheet not found.', 0, $e);
        }
    }

    public function createSheet(): Sheet
    {
        if (!$this->config->hasWorksheetName()) {
            throw new InvalidConfigException(
                'To create worksheet please configure "parameters.worksheet.name".'
            );
        }

        $sheet = $this->getSheetByName($this->getFile(), $this->config->getWorksheetName());
        if (!$sheet->isNew()) {
            throw new WorkbookAlreadyExistsException(
                sprintf('Worksheet "%s" already exists.', $this->config->getWorksheetName())
            );
        }

        return $sheet;
    }

    public function getFile(): SheetFile
    {
        $config = $this->config;

        // Get by IDS
        if ($config->hasDriveId() && $config->hasFileId()) {
            $this->api->createWorkbookSessionId($config->getDriveId(), $config->getFileId());
            return $this->getFileByIds($config->getDriveId(), $config->getFileId());
        }

        // Search or create by path
        return $this->getFileByPath($config->getPath());
    }

    public function createFile(): SheetFile
    {
        if (!$this->config->hasPath()) {
            throw new InvalidConfigException(
                'To create workbook please configure "parameters.workbook.path".'
            );
        }

        $file = $this->getFileByPath($this->config->getPath());
        if (!$file->isNew()) {
            throw new WorkbookAlreadyExistsException(
                sprintf('Workbook "%s" already exists.', $this->config->getPath())
            );
        }

        return $file;
    }

    private function getFileByIds(string $driveId, string $fileId): SheetFile
    {
        try {
            // Check if workbook exists
            $this->api->getSheets($driveId, $fileId)->current();
            return new SheetFile($driveId, $fileId, false);
        } catch (ResourceNotFoundException $e) {
            throw new ResourceNotFoundException('Configured workbook XLSX file not found.', 0, $e);
        }
    }

    private function getFileByPath(string $path): SheetFile
    {
        try {
            $file = $this->api->searchWorkbook($path);
            $new = false;
        } catch (FileInDriveNotFoundException $e) {
            $file = $this->api->createEmptyWorkbook($e->getEndpointUrl());
            $new = true;
        } catch (ResourceNotFoundException $e) {
            throw new ResourceNotFoundException(
                sprintf('No file found when searching for "%s".', $path),
                0,
                $e
            );
        }

        return new SheetFile($file->getDriveId(), $file->getFileId(), $new);
    }

    private function getSheetById(SheetFile $workbook, string $sheetId): Sheet
    {
        $sheetName = $this->api->getSheetName($workbook->getDriveId(), $workbook->getFileId(), $sheetId);
        return new Sheet($workbook, $sheetId, $sheetName, false);
    }

    private function getSheetByPosition(SheetFile $workbook, int $position): Sheet
    {
        $position = $this->config->getWorksheetPosition();
        $sheetId = $this->api->getSheetIdByPosition($workbook->getDriveId(), $workbook->getFileId(), $position);
        $sheetName = $this->api->getSheetName($workbook->getDriveId(), $workbook->getFileId(), $sheetId);
        return new Sheet($workbook, $sheetId, $sheetName, false);
    }

    private function getSheetByName(SheetFile $workbook, string $sheetName): Sheet
    {
        try {
            $sheetId = $this->api->getSheetIdByName(
                $workbook->getDriveId(),
                $workbook->getFileId(),
                $sheetName
            );
            $new = false;
        } catch (ResourceNotFoundException $e) {
            $sheetId = $this->api->createSheet($workbook->getDriveId(), $workbook->getFileId(), $sheetName);
            $new = true;
        }

        return new Sheet($workbook, $sheetId, $sheetName, $new);
    }
}
