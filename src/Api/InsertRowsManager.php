<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api;

use Iterator;
use NoRewindIterator;
use LimitIterator;
use PHPUnit\TextUI\Help;
use Psr\Log\LoggerInterface;
use Keboola\OneDriveWriter\Sheet;
use Keboola\OneDriveWriter\Api\Model\TableHeader;

class InsertRowsManager
{
    private LoggerInterface $logger;
    private Api $api;

    public function __construct(LoggerInterface $logger, Api $api)
    {
        $this->logger = $logger;
        $this->api = $api;
    }

    public function insert(Sheet $sheet, bool $append, Iterator $rows, int $batchSize): void
    {
        // Clear
        if (!$append && !$sheet->isNew()) {
            $this->api->clearSheet($sheet);
        }

        // Determine offset
        $range = !$sheet->isNew() && $append ?
            $this->api->getSheetRange($sheet) : null;
        $orgHeader = $range && !$range->isEmpty() ?
            $this->api->getSheetHeader($sheet) : null;

        if ($range && !$range->isEmpty()) {
            $startCol = Helpers::columnStrToInt($range->getStartColumn());
            $startRow = $range->getLastRowNumber() + 1;
        } else {
            $startCol = 1;
            $startRow = 1;
        }

        // Parse header
        $header = $rows->current();
        $headerColumns = TableHeader::parseColumns($header);
        $endCol = $startCol + count($headerColumns) - 1;

        // Check header
        if ($orgHeader && $orgHeader->getColumns() !== $headerColumns) {
            $this->logger->warning(sprintf(
                'Headers mismatch. Ignored new header: %s',
                Helpers::formatIterable($headerColumns)
            ));
        }

        // Insert in parts
        $iterator = new NoRewindIterator($rows);

        // Skip header if header present in target file
        if ($orgHeader && !$orgHeader->isEmpty()) {
            $iterator->next();
        }

        do {
            // use_keys = false, important!
            $values = iterator_to_array(new LimitIterator($iterator, 0, $batchSize), false);
            if (empty($values)) {
                break;
            }

            // escape
            Helpers::escapeExcelExpressions($values);

            $endRow = $startRow + count($values) - 1;
            $endpoint = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
            $uri = $endpoint . '/range(address=\'{startCol}{startRow}:{endCol}{endRow}\')';

            $this->api->patch(
                $uri,
                [
                    'driveId' => $sheet->getDriveId(),
                    'fileId' => $sheet->getFileId(),
                    'worksheetId' => $sheet->getId(),
                    'startCol' => Helpers::columnIntToStr($startCol),
                    'startRow' => $startRow,
                    'endCol' => Helpers::columnIntToStr($endCol),
                    'endRow' => $endRow,
                ],
                ['values' => $values]
            );

            $this->logger->info(sprintf('Inserted %s rows.', count($values)));

            $startRow = $endRow + 1;
        } while (true);
    }
}
