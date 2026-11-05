<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api;

use Iterator;
use NoRewindIterator;
use LimitIterator;
use Keboola\OneDriveWriter\Exception\UserException;
use Psr\Log\LoggerInterface;
use Keboola\OneDriveWriter\Sheet;
use Keboola\OneDriveWriter\Api\Model\TableHeader;

class InsertRowsManager
{
    /**
     * Maximum number of times the writer repairs the sheet after the workbook session expires.
     */
    public const MAX_SESSION_RECOVERIES = 3;

    private LoggerInterface $logger;
    private Api $api;

    public function __construct(LoggerInterface $logger, Api $api)
    {
        $this->logger = $logger;
        $this->api = $api;
    }

    /**
     * @param callable|null $rowsFactory Creates a new iterator over the same source rows.
     *     Microsoft can discard rows that the API accepted in a workbook session that later becomes
     *     invalid. If that occurs, the writer uses this factory to read the affected rows again and
     *     to write them again. Without the factory the writer cannot repair the sheet, so it fails
     *     instead of leaving empty rows in the sheet without an error.
     */
    public function insert(
        Sheet $sheet,
        bool $append,
        Iterator $rows,
        int $batchSize,
        ?callable $rowsFactory = null
    ): void {
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
        $skippedRows = 0;
        if ($orgHeader && !$orgHeader->isEmpty()) {
            $iterator->next();
            $skippedRows = 1;
        }

        // Batches that are written in the current session. The API can discard them if the
        // session becomes invalid, so the writer must be able to write them again.
        /** @var array<int, array{startRow: int, endRow: int, count: int}> $unconfirmedBatches */
        $unconfirmedBatches = [];
        $sessionLostCount = $this->api->getSessionLostCount();

        do {
            // use_keys = false, important!
            $values = iterator_to_array(new LimitIterator($iterator, 0, $batchSize), false);
            if (empty($values)) {
                break;
            }

            // escape
            Helpers::escapeExcelExpressions($values);

            $endRow = $startRow + count($values) - 1;
            $this->writeBatch($sheet, $startCol, $startRow, $endCol, $endRow, $values);
            $this->logger->info(sprintf('Inserted %s rows.', count($values)));

            $unconfirmedBatches[] = ['startRow' => $startRow, 'endRow' => $endRow, 'count' => count($values)];

            // The write above can invalidate the session. In that case the API wrote this batch
            // again without a session, but the batches that it accepted in the lost session can
            // be missing in the file, so the writer writes them again.
            if ($this->api->getSessionLostCount() !== $sessionLostCount) {
                $this->repairLostSession($sheet, $startCol, $endCol, $unconfirmedBatches, $skippedRows, $rowsFactory);
                $sessionLostCount = $this->api->getSessionLostCount();
            }

            // The API saves a request that uses no session immediately, so no repair can be needed.
            if (!$this->api->hasSession()) {
                $unconfirmedBatches = [];
            }

            $startRow = $endRow + 1;
        } while (true);
    }

    /**
     * @param array<int, array{startRow: int, endRow: int, count: int}> $batches
     * @param callable|null $rowsFactory
     */
    private function repairLostSession(
        Sheet $sheet,
        int $startCol,
        int $endCol,
        array $batches,
        int $skippedRows,
        ?callable $rowsFactory
    ): void {
        if (empty($batches)) {
            return;
        }

        if ($rowsFactory === null) {
            throw new UserException(
                'The workbook session expired while the writer wrote the data. ' .
                'Microsoft can discard rows that the API accepted in an expired session, ' .
                'and the writer cannot read the source rows again to write them again. ' .
                'The job failed, because the sheet can be incomplete. Please run the job again.'
            );
        }

        for ($attempt = 1; $attempt <= self::MAX_SESSION_RECOVERIES; $attempt++) {
            $sessionLostCount = $this->api->getSessionLostCount();
            $this->logger->warning(sprintf(
                'The workbook session expired. Microsoft can discard rows that the API accepted ' .
                'in an expired session, so the writer writes the last %d batches again.',
                count($batches)
            ));

            $this->rewriteBatches($sheet, $startCol, $endCol, $batches, $skippedRows, $rowsFactory);

            // The session can expire again while the rows are written again.
            if ($this->api->getSessionLostCount() === $sessionLostCount) {
                return;
            }
        }

        throw new UserException(sprintf(
            'The workbook session expired %d times. ' .
            'The job failed, because the sheet can be incomplete. Please run the job again.',
            self::MAX_SESSION_RECOVERIES + 1
        ));
    }

    /**
     * @param array<int, array{startRow: int, endRow: int, count: int}> $batches
     * @param callable $rowsFactory Creates a new iterator over the same source rows.
     */
    private function rewriteBatches(
        Sheet $sheet,
        int $startCol,
        int $endCol,
        array $batches,
        int $skippedRows,
        callable $rowsFactory
    ): void {
        $rows = $rowsFactory();
        if (!$rows instanceof Iterator) {
            throw new UserException('The source rows could not be read again.');
        }

        $rows->rewind();
        for ($i = 0; $i < $skippedRows; $i++) {
            $rows->next();
        }

        foreach ($batches as $batch) {
            $values = [];
            while ($rows->valid() && count($values) < $batch['count']) {
                $values[] = $rows->current();
                $rows->next();
            }

            if (count($values) !== $batch['count']) {
                throw new UserException(
                    'The source rows changed while the writer wrote the data. ' .
                    'The job failed, because the sheet can be incomplete. Please run the job again.'
                );
            }

            Helpers::escapeExcelExpressions($values);
            $this->writeBatch($sheet, $startCol, $batch['startRow'], $endCol, $batch['endRow'], $values);
            $this->logger->info(sprintf('Written %s rows again.', count($values)));
        }
    }

    /**
     * @param array<int, mixed> $values
     */
    private function writeBatch(
        Sheet $sheet,
        int $startCol,
        int $startRow,
        int $endCol,
        int $endRow,
        array $values
    ): void {
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
    }
}
