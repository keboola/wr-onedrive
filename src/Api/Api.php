<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Keboola\OneDriveWriter\Api\Model\WorkbookSession;
use Keboola\OneDriveWriter\Exception\GatewayTimeoutException;
use Keboola\OneDriveWriter\Exception\UserException;
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
    public const RETRY_HTTP_CODES = [
        405, // 405 - Method not allowed, it occurs when the creation of a new sheet is not yet fully propagated
        409, // 409 Conflict
        500, // 500 Internal Serve Error
        502, // 502 Bad Gateway
        503, // 503 Service Unavailable
        504, // 504 Gateway Timeout
    ];

    public const RETRY_MAX_ATTEMPTS = 14;
    public const MAX_INTERVAL = 5000;

    private Graph $graphApi;

    private LoggerInterface $logger;

    private ?ClientInterface $httpClient = null;

    private ?WorkbookSession $workbookSession = null;

    public function __construct(Graph $graphApi, LoggerInterface $logger)
    {
        $this->graphApi = $graphApi;
        $this->logger = $logger;
    }

    public function __destruct()
    {
        $this->closeSession();
    }

    public function hasSessionId(): bool
    {
        return $this->workbookSession !== null;
    }

    public function getSessionId(): ?string
    {
        return $this->workbookSession ? $this->workbookSession->getSessionId() : null;
    }

    public function getAccountName(): string
    {
        $response = $this->get('/me?$select=userPrincipalName')->getBody();
        return (string) $response['userPrincipalName'];
    }

    public function createEmptyWorkbook(string $endpoint): File
    {
        $uploader = new FileUploader($this);
        $file = $uploader->upload($endpoint, __DIR__ . '/Fixtures/empty.xlsx');
        $this->createWorkbookSessionId($file->getDriveId(), $file->getFileId());
        $this->logger->info(sprintf('New workbook "%s" created.', implode('/', $file->getPathname())));
        return $file;
    }

    public function insertRows(
        Sheet $sheet,
        bool $append,
        Iterator $rows,
        int $batchSize = 30000
    ): void {
        $manager = new InsertRowsManager($this->logger, $this);
        $manager->insert($sheet, $append, $rows, $batchSize);
    }

    public function clearSheet(Sheet $sheet): void
    {
        $endpoint = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
        $uri = $endpoint . '/range/clear';

        $headers = [];
        if ($this->workbookSession instanceof WorkbookSession) {
            $headers['Workbook-Session-Id'] = $this->workbookSession->getSessionId();
        }

        $this->post(
            $uri,
            [
                'driveId' => $sheet->getDriveId(),
                'fileId' => $sheet->getFileId(),
                'worksheetId' => $sheet->getId(),
            ],
            [ 'applyTo' => 'all',],
            $headers
        );
        $this->logger->info('Sheet cleared.');
    }

    public function createSheet(string $driveId, string $fileId, string $newName): string
    {
        $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets';
        $headers = [];
        if ($this->workbookSession instanceof WorkbookSession) {
            $headers['Workbook-Session-Id'] = $this->workbookSession->getSessionId();
        }
        $body = $this->post(
            $uri,
            ['driveId' => $driveId, 'fileId' => $fileId],
            [ 'name' => $newName],
            $headers
        )->getBody();
        $this->logger->info(sprintf('New sheet "%s" created.', $newName));
        return $body['id'];
    }

    public function renameSheet(
        string $driveId,
        string $fileId,
        string $worksheetId,
        string $newName
    ): void {
        $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';

        $headers = [];
        if ($this->workbookSession instanceof WorkbookSession) {
            $headers['Workbook-Session-Id'] = $this->workbookSession->getSessionId();
        }

        $this->patch(
            $uri,
            ['driveId' => $driveId, 'fileId' => $fileId, 'worksheetId' => $worksheetId],
            [ 'name' => $newName],
            $headers
        );

        $this->logger->info(sprintf('Sheet renamed to "%s".', $newName));
    }

    public function getSheetHeader(Sheet $sheet): TableHeader
    {
        // Table header is first row in worksheet
        // Table can be shifted because we use "usedRange".
        $endpoint = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
        $uri = $endpoint . '/usedRange(valuesOnly=true)/row(row=0)?$select=address,text';

        $headers = [];
        if ($this->workbookSession instanceof WorkbookSession) {
            $headers['Workbook-Session-Id'] = $this->workbookSession->getSessionId();
        }

        $body = $this
            ->get(
                $uri,
                [
                    'driveId' => $sheet->getDriveId(),
                    'fileId' => $sheet->getFileId(),
                    'worksheetId' => $sheet->getId(),
                ],
                $headers
            )
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

        $headers = [];
        if ($this->workbookSession instanceof WorkbookSession) {
            $headers['Workbook-Session-Id'] = $this->workbookSession->getSessionId();
        }

        $body = $this
            ->get(
                $uri,
                [
                    'driveId' => $sheet->getDriveId(),
                    'fileId' => $sheet->getFileId(),
                    'worksheetId' => $sheet->getId(),
                ],
                $headers
            )
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
        $headers = [];
        if ($this->workbookSession instanceof WorkbookSession) {
            $headers['Workbook-Session-Id'] = $this->workbookSession->getSessionId();
        }
        $body = $this
            ->get($uri, ['driveId' => $driveId, 'fileId' => $fileId, 'worksheetId' => $worksheetId], $headers)
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
        $headers = [];
        if ($this->workbookSession instanceof WorkbookSession) {
            $headers['Workbook-Session-Id'] = $this->workbookSession->getSessionId();
        }
        $body = $this->get($uri, ['driveId' => $driveId, 'fileId' => $fileId], $headers)->getBody();

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
        $headers = [];
        if ($this->workbookSession instanceof WorkbookSession) {
            $headers['Workbook-Session-Id'] = $this->workbookSession->getSessionId();
        }

        $body = $this
            ->get($uri, ['driveId' => $driveId, 'fileId' => $fileId], $headers)
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

    public function createWorkbookSessionId(string $driveId, string $fileId): void
    {
        $uri = '/drives/{driveId}/items/{fileId}/workbook/createSession';

        try {
            $response = $this->post(
                $uri,
                [
                    'driveId' => $driveId,
                    'fileId' => $fileId,
                ],
                [
                    'persistChanges' => true,
                ],
                [
                    'Prefer' => 'respond-async',
                ],
            );
        } catch (ResourceNotFoundException $e) {
            throw new ResourceNotFoundException('Configured workbook XLSX file not found.', 0, $e);
        }

        switch ($response->getStatus()) {
            case 201:
                $this->workbookSession = new WorkbookSession($driveId, $fileId, $response->getBody()['id']);
                $this->logger->info('Write data using the session.');
                return;
            case 202:
                $responseHeader = $response->getHeaders();

                $sessionLocation = current($responseHeader['Location']);

                $status = 'running';
                while ($status === 'running') {
                    sleep(2);
                    $session = $this->get($sessionLocation)->getBody();
                    $status = $session['status'];
                }

                if ($status !== 'succeeded') {
                    $this->logger->info('The workbook session could not be created.');
                    return;
                }

                $sessionResource = $this->get($session['resourceLocation'])->getBody();

                $this->workbookSession = new WorkbookSession($driveId, $fileId, $sessionResource['id']);
                $this->logger->info('Write data using the session.');
                return;
            default:
                $this->logger->info('The workbook session could not be created.');
        }
    }

    public function closeSession(): void
    {
        if (!$this->workbookSession) {
            return;
        }

        $uri = '/drives/{driveId}/items/{fileId}/workbook/closeSession';
        try {
            $this->post(
                $uri,
                [
                    'driveId' => $this->workbookSession->getDriveId(),
                    'fileId' => $this->workbookSession->getFileId(),
                ],
                [],
                [
                    'Workbook-Session-Id' => $this->workbookSession->getSessionId(),
                ],
            );
        } catch (Throwable $e) {
        }
    }

    public function searchWorkbook(string $search = ''): File
    {
        $finder = new WorkbooksFinder($this, $this->logger);
        $file = $finder->search($search);
        $this->createWorkbookSessionId($file->getDriveId(), $file->getFileId());
        $this->logger->info(sprintf('Found workbook "%s".', $file->getName()));
        return $file;
    }

    public function getGraph(): Graph
    {
        return $this->graphApi;
    }

    public function createBatchRequest(): BatchRequest
    {
        return new BatchRequest($this);
    }

    public function get(string $uri, array $params = [], array $headers = []): GraphResponse
    {
        return $this->executeWithRetry('GET', $uri, $params, [], $headers);
    }

    public function post(string $uri, array $params = [], array $body = [], array $headers = []): GraphResponse
    {
        return $this->executeWithRetry('POST', $uri, $params, $body, $headers);
    }

    public function patch(string $uri, array $params = [], array $body = [], array $headers = []): GraphResponse
    {
        return $this->executeWithRetry('PATCH', $uri, $params, $body, $headers);
    }

    private function executeWithRetry(
        string $method,
        string $uri,
        array $params = [],
        array $body = [],
        array $headers = []
    ): GraphResponse {
        $backOffPolicy = new ExponentialBackOffPolicy(500, 2.0, self::MAX_INTERVAL);
        $retryPolicy = new CallableRetryPolicy(function (Throwable $e) {
            // Retry on connect exception, eg. Could not resolve host: login.microsoftonline.com
            if ($e instanceof ConnectException) {
                return true;
            }

            if ($e instanceof RequestException
                || $e instanceof BatchRequestException
                || $e instanceof GatewayTimeoutException
            ) {
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

            if ($e instanceof UserException && $e->getCode() === 429) {
                $previous = $e->getPrevious();
                assert($previous instanceof RequestException);
                assert($previous->getResponse() !== null);
                if ($previous->getResponse()->hasHeader('Retry-After')) {
                    if ((int) $previous->getResponse()->getHeader('Retry-After')[0] > self::MAX_INTERVAL) {
                        return false;
                    }
                }
                return true;
            }

            return false;
        }, self::RETRY_MAX_ATTEMPTS);
        $proxy = new RetryProxy($retryPolicy, $backOffPolicy, $this->logger);
        return $proxy->call(function () use ($method, $uri, $params, $body, $headers) {
            return $this->execute($method, $uri, $params, $body, $headers);
        });
    }

    private function execute(
        string $method,
        string $uri,
        array $params = [],
        array $body = [],
        array $headers = []
    ): GraphResponse {
        $uri = Helpers::replaceParamsInUri($uri, $params);
        $request = $this->graphApi->createRequest($method, $uri);

        if ($body) {
            $request->attachBody($body);
        }

        if ($headers) {
            $request->addHeaders($headers);
        }

        try {
            return $request->execute($this->httpClient);
        } catch (RequestException $e) {
            throw Helpers::processRequestException($e);
        }
    }

    public function setHttpClient(ClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }
}
