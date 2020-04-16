<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api;

use Throwable;
use Iterator;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\RequestException;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Http\GraphResponse;
use Retry\RetryProxy;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\CallableRetryPolicy;
use Keboola\OneDriveWriter\Sheet;
use Keboola\OneDriveWriter\Api\Model\TableRange;
use Keboola\OneDriveWriter\Api\Model\TableHeader;
use Keboola\OneDriveWriter\Exception\BatchRequestException;
use Keboola\OneDriveWriter\Api\Batch\BatchRequest;
use Keboola\OneDriveWriter\Api\Model\File;
use Keboola\OneDriveWriter\Api\Model\Site;
use Keboola\OneDriveWriter\Api\Model\Worksheet;
use Keboola\OneDriveWriter\Exception\ResourceNotFoundException;
use Keboola\OneDriveWriter\Exception\UnexpectedCountException;
use Keboola\OneDriveWriter\Exception\UnexpectedValueException;

class Api
{
    // Retry on 409 Conflict, Internal Serve Error, Bad Gateway, Service Unavailable and Gateway Timeout
    public const RETRY_HTTP_CODES = [409, 500, 502, 503, 504];

    private Graph $graphApi;

    private LoggerInterface $logger;

    public function __construct(Graph $graphApi, LoggerInterface $logger)
    {
        $this->graphApi = $graphApi;
        $this->logger = $logger;
    }

    public function getAccountName(): string
    {
        $response = $this->get('/me?$select=userPrincipalName')->getBody();
        return (string) $response['userPrincipalName'];
    }

    public function createEmptyFile(string $endpoint): File
    {
        $uploader = new FileUploader($this);
        $file = $uploader->upload($endpoint, __DIR__ . '/Fixtures/empty.xlsx');
        $this->logger->info(sprintf('New file "%s" created.', implode('/', $file->getPathname())));
        return $file;
    }

    public function insertRows(Sheet $sheet, bool $append, Iterator $rows, int $bulk = 30000): void
    {
        $manager = new InsertRowsManager($this->logger, $this);
        $manager->insert($sheet, $append, $rows, $bulk);
    }

    public function clearSheet(Sheet $sheet): void
    {
        $endpoint = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
        $uri = $endpoint . '/range/clear';
        $this->post(
            $uri,
            [
                'driveId' => $sheet->getDriveId(),
                'fileId' => $sheet->getFileId(),
                'worksheetId' => $sheet->getId(),
            ],
            [ 'applyTo' => 'all',]
        );
        $this->logger->info('Sheet cleared.');
    }

    public function createSheet(string $driveId, string $fileId, string $newName): string
    {
        $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets';
        $body = $this->post($uri, ['driveId' => $driveId, 'fileId' => $fileId], [ 'name' => $newName])->getBody();
        $this->logger->info(sprintf('New sheet "%s" created.', $newName));
        return $body['id'];
    }

    public function renameSheet(string $driveId, string $fileId, string $worksheetId, string $newName): void
    {
        $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
        $this->patch(
            $uri,
            ['driveId' => $driveId, 'fileId' => $fileId, 'worksheetId' => $worksheetId],
            [ 'name' => $newName]
        );

        $this->logger->info(sprintf('Sheet renamed to "%s".', $newName));
    }

    public function getSheetHeader(Sheet $sheet): TableHeader
    {
        // Table header is first row in worksheet
        // Table can be shifted because we use "usedRange".
        $endpoint = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
        $uri = $endpoint . '/usedRange(valuesOnly=true)/row(row=0)?$select=address,text';
        $body = $this
            ->get($uri, [
                'driveId' => $sheet->getDriveId(),
                'fileId' => $sheet->getFileId(),
                'worksheetId' => $sheet->getId(),
            ])
            ->getBody();

        $header = TableHeader::from($body['address'], $body['text'][0]);
        $this->logger->info(sprintf(
            'Current sheet header "%s": %s',
            $header->getAddress(),
            Helpers::formatIterable($header->getColumns()),
        ));
        return $header;
    }

    public function getSheetRange(Sheet $sheet): TableRange
    {
        $endpoint = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
        $uri = $endpoint . '/usedRange(valuesOnly=true)?$select=address';
        $body = $this
            ->get($uri, [
                'driveId' => $sheet->getDriveId(),
                'fileId' => $sheet->getFileId(),
                'worksheetId' => $sheet->getId(),
            ])
            ->getBody();

        $range =  TableRange::from($body['address']);
        if ($range->isEmpty()) {
            $this->logger->info('Sheet is empty.');
        } else {
            $this->logger->info(sprintf('Current sheet range: "%s"', $range->getAddress()));
        }

        return $range;
    }


    public function getSheetName(string $driveId, string $fileId, string $worksheetId): string
    {
        $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}?$select=name';
        $body = $this
            ->get($uri, ['driveId' => $driveId, 'fileId' => $fileId, 'worksheetId' => $worksheetId])
            ->getBody();
        return $body['name'];
    }

    public function getSheetIdByName(string $driveId, string $fileId, string $name): string
    {
        // Load list of worksheets in workbook
        $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets?$select=id,name,position';
        $body = $this->get($uri, ['driveId' => $driveId, 'fileId' => $fileId])->getBody();

        // Search by position
        $worksheet = null;
        foreach ($body['value'] as $data) {
            if ($data['name'] === $name) {
                $worksheet = $data;
                break;
            }
        }

        // Log and return
        if ($worksheet) {
            $this->logger->info(sprintf(
                'Found worksheet "%s" at position "%s".',
                $worksheet['name'],
                $worksheet['position']
            ));
            return $worksheet['id'];
        }

        throw new ResourceNotFoundException(sprintf('No worksheet with name "%s".', $name));
    }


    public function getSheetIdByPosition(string $driveId, string $fileId, int $position): string
    {
        // Check position value, must be greater than zero
        if ($position < 0) {
            throw new UnexpectedValueException(sprintf(
                'Worksheet position must be greater than zero. Given "%d".',
                $position
            ));
        }

        // Load list of worksheets in workbook
        $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets?$select=id,name,position';
        $body = $this->get($uri, ['driveId' => $driveId, 'fileId' => $fileId])->getBody();

        // Search by position
        $worksheet = null;
        foreach ($body['value'] as $data) {
            if ($data['position'] === $position) {
                $worksheet = $data;
                break;
            }
        }

        // Log and return
        if ($worksheet) {
            $this->logger->info(sprintf(
                'Found worksheet "%s" at position "%s".',
                $worksheet['name'],
                $position
            ));
            return $worksheet['id'];
        }

        throw new ResourceNotFoundException(sprintf('No worksheet at position "%d".', $position));
    }

    /**
     * @return Iterator|Worksheet[]
     */
    public function getSheets(string $driveId, string $fileId): Iterator
    {
        // Load list of worksheets in workbook
        $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets?$select=id,position,name,visibility';
        $body = $this
            ->get($uri, ['driveId' => $driveId, 'fileId' => $fileId])
            ->getBody();

        // Map to object and load header in batch request
        $batch = $this->createBatchRequest();
        foreach ($body['value'] as $data) {
            $worksheet = Worksheet::from($data, $driveId, $fileId);
            $endpoint = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
            $uri = $endpoint . '/usedRange(valuesOnly=true)/row(row=0)?$select=address,text';
            $args = ['driveId' => $driveId, 'fileId' => $fileId, 'worksheetId' => $worksheet->getWorksheetId()];
            $batch->addRequest($uri, $args, function (array $body) use ($worksheet) {
                $header = TableHeader::from($body['address'], $body['text'][0]);
                $worksheet->setHeader($header);
                yield $worksheet;
            });
        }

        // Load headers for worksheets in one request, sort by position
        $worksheets = iterator_to_array($batch->execute());
        usort($worksheets, fn(Worksheet $a, Worksheet $b) => $a->getPosition() - $b->getPosition());
        yield from $worksheets;
    }

    public function getSite(string $name): Site
    {
        $response = $this->get('/sites?search={name}&$select=id,name', ['name' => $name]);
        $body = $response->getBody();
        $count = count($body['value']);
        if ($count === 1) {
            $siteData = $body['value'][0];
            return Site::from($siteData);
        } elseif ($count === 0) {
            throw new ResourceNotFoundException(sprintf('Site "%s" not found.', $name));
        } else {
            throw new UnexpectedCountException(sprintf('Multiple sites found when searching for "%s".', $name));
        }
    }

    public function searchWorkbook(string $search = ''): File
    {
        $finder = new WorkbooksFinder($this, $this->logger);
        return $finder->search($search);
    }

    public function getGraph(): Graph
    {
        return $this->graphApi;
    }

    public function createBatchRequest(): BatchRequest
    {
        return new BatchRequest($this);
    }

    public function get(string $uri, array $params = []): GraphResponse
    {
        return $this->executeWithRetry('GET', $uri, $params);
    }

    public function post(string $uri, array $params = [], array $body = []): GraphResponse
    {
        return $this->executeWithRetry('POST', $uri, $params, $body);
    }

    public function patch(string $uri, array $params = [], array $body = []): GraphResponse
    {
        return $this->executeWithRetry('PATCH', $uri, $params, $body);
    }

    private function executeWithRetry(string $method, string $uri, array $params = [], array $body = []): GraphResponse
    {
        $backOffPolicy = new ExponentialBackOffPolicy(100, 2.0, 4000);
        $retryPolicy = new CallableRetryPolicy(function (Throwable $e) {
            if ($e instanceof RequestException || $e instanceof BatchRequestException) {
                // Retry only on defined HTTP codes
                if (in_array($e->getCode(), self::RETRY_HTTP_CODES, true)) {
                    return true;
                }

                // Retry if communication problems
                if (strpos($e->getMessage(), 'There were communication or server problems') !== false) {
                    return true;
                }

                if (strpos($e->getMessage(), 'EditModeCannotAcquireLockTooManyRequests') !== false) {
                    return true;
                }
            }

            return false;
        });

        $proxy = new RetryProxy($retryPolicy, $backOffPolicy);
        return $proxy->call(function () use ($method, $uri, $params, $body) {
            return $this->execute($method, $uri, $params, $body);
        });
    }

    private function execute(string $method, string $uri, array $params = [], array $body = []): GraphResponse
    {
        $uri = Helpers::replaceParamsInUri($uri, $params);
        $request = $this->graphApi->createRequest($method, $uri);
        if ($body) {
            $request->attachBody($body);
        }

        try {
            return $request->execute();
        } catch (RequestException $e) {
            throw Helpers::processRequestException($e);
        }
    }
}
