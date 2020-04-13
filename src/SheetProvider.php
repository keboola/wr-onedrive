<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter;

use Keboola\OneDriveWriter\Api\Api;
use Keboola\OneDriveWriter\Configuration\Config;
use Keboola\OneDriveWriter\Exception\FileInDriveNotFoundException;
use Keboola\OneDriveWriter\Exception\ResourceNotFoundException;

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
                // By ids
                $sheetId = $config->getWorksheetId();
                $sheetName = $this->api->getSheetName($workbook->getDriveId(), $workbook->getFileId(), $sheetId);
                $new = false;
            } elseif ($config->hasWorksheetPosition()) {
                // By position
                $position = $this->config->getWorksheetPosition();
                $sheetId = $this->api->getSheetIdByPosition($workbook->getDriveId(), $workbook->getFileId(), $position);
                $sheetName = $this->api->getSheetName($workbook->getDriveId(), $workbook->getFileId(), $sheetId);
                $new = false;
            } else {
                // By name
                $sheetName = $config->getWorksheetName();
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
            }
        } catch (ResourceNotFoundException $e) {
            throw new ResourceNotFoundException('Worksheet not found.', 0, $e);
        }

        return new Sheet($workbook, $sheetId, $sheetName, $new);
    }

    public function getFile(): SheetFile
    {
        $config = $this->config;
        if ($config->hasDriveId() && $config->hasFileId()) {
            $driveId = $config->getDriveId();
            $fileId = $config->getFileId();
            try {
                // Check if workbook exists
                $this->api->getSheets($driveId, $fileId)->current();
            } catch (ResourceNotFoundException $e) {
                throw new ResourceNotFoundException('Configured workbook XLSX file not found.', 0, $e);
            }
        } else {
            [$driveId, $fileId] = $this->searchOrCreateFile($config->getPath());
        }

        return new SheetFile($driveId, $fileId);
    }

    private function searchOrCreateFile(string $search): array
    {
        try {
            $file = $this->api->searchWorkbook($search);
        } catch (FileInDriveNotFoundException $e) {
            $file = $this->api->createEmptyFile($e->getEndpointUrl());
        } catch (ResourceNotFoundException $e) {
            throw new ResourceNotFoundException(
                sprintf('No file found when searching for "%s".', $search),
                0,
                $e
            );
        }

        return [$file->getDriveId(), $file->getFileId()];
    }
}
