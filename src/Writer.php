<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter;

use Keboola\OneDriveWriter\Configuration\Config;
use SplFileInfo;
use Psr\Log\LoggerInterface;
use Keboola\Csv\CsvReader;
use Keboola\OneDriveWriter\Exception\CsvFileException;
use Keboola\OneDriveWriter\Api\Api;
use Symfony\Component\Finder\Finder;

class Writer
{
    private LoggerInterface $logger;

    private Api $api;

    private string $inputDir;

    private Config $config;

    public function __construct(
        LoggerInterface $logger,
        Api $api,
        string $dataDir,
        Config $config
    ) {
        $this->logger = $logger;
        $this->api = $api;
        $this->inputDir = $dataDir . '/in/tables';
        $this->config = $config;
    }

    public function write(Sheet $sheet): void
    {
        $file = $this->findCsv();
        $csv = new CsvReader($file->getPathname());
        $header = $csv->getHeader();

        // Ignore empty file
        if (empty($header)) {
            $this->logger->warning(sprintf('Ignored empty CSV file "%s".', $file->getBasename()));
            return;
        }

        $sessionId = $this->api->getWorkbookSessionId(
            $sheet->getDriveId(),
            $sheet->getFileId(),
        );

        // Rename sheet
        if ($this->config->hasWorksheetName() && $this->config->getWorksheetName() !== $sheet->getName()) {
            $this->api->renameSheet(
                $sheet->getDriveId(),
                $sheet->getFileId(),
                $sheet->getId(),
                $this->config->getWorksheetName(),
                $sessionId
            );
        }

        // Insert rows
        $this->api->insertRows($sheet, $this->config->getAppend(), $csv, $this->config->getBatchSize(), $sessionId);

        // Close session if exists
        if ($sessionId) {
            $this->api->closeSession($sheet->getDriveId(), $sheet->getFileId(), $sessionId);
        }
    }

    private function findCsv(): SplFileInfo
    {
        // Find CSV files in input directory
        $finder = new Finder();
        $files = iterator_to_array(
            $finder->files()->in($this->inputDir)->name('*.csv')->getIterator(),
            false
        );

        // Expected is exact one CSV file
        if (count($files) === 0) {
            throw new CsvFileException(sprintf('No CSV file found in "%s".', $this->inputDir));
        } elseif (count($files) > 1) {
            throw new CsvFileException(sprintf(
                'Expected one CSV file, found multiple: "%s".',
                implode('", "', array_map(fn(SplFileInfo $file) => $file->getBasename(), $files))
            ));
        }

        /** @var SplFileInfo $file */
        $file = $files[0];
        $this->logger->info(sprintf('Found input CSV file "%s".', $file->getBasename()));
        return $file;
    }
}
