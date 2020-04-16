<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Fixtures;

use Iterator;
use Throwable;
use RuntimeException;
use GuzzleHttp\Exception\ClientException;
use Microsoft\Graph\Model;
use Symfony\Component\Finder\Finder;
use Keboola\OneDriveWriter\Api\Helpers;

class FixturesUtils
{
    private static bool $logEnabled = true;

    private FixturesApi $api;

    public static function disableLog(): void
    {
        self::$logEnabled = false;
    }

    public static function log(string $text): void
    {
        if (self::$logEnabled) {
            echo empty($text) ? "\n" : "FixturesUtils: {$text}\n";
        }
    }

    public static function createTmpFilePath(?string $name = null): array
    {
        $drivePath = FixturesCatalog::TMP_DIR;
        if (!$name) {
            $name = 'tmp_' . substr(uniqid(), 0, 16) . '.xlsx';
        }
        return [$drivePath, $name];
    }

    public function __construct()
    {
        $this->api = new FixturesApi();
    }

    public function getMeDriveId(): string
    {
        $response = $this->api->get('/me/drive?$select=id');
        $body = $response->getBody();
        return $body['id'];
    }

    public function getSharePointSiteDriveId(string $siteName): string
    {
        // Load site
        $uri = '/sites?search={siteName}&$select=id,name';
        $body = $this->api->get($uri, ['siteName' => $siteName])->getBody();
        $sites = $body['value'];
        if (count($body['value']) === 0) {
            throw new RuntimeException(sprintf(
                'SharePoint site "%s" not found.',
                $siteName
            ));
        } elseif (count($body['value']) > 1) {
            throw new RuntimeException(sprintf(
                'Multiple SharePoint sites "%s" found when searching for "%s".',
                implode('", "', array_map(fn(array $site) => $site['name'], $sites)),
                $siteName
            ));
        }

        // Load drive id
        $siteId = $body['value'][0]['id'];
        $uri = '/sites/{siteId}/drive?$select=id';
        $body = $this->api->get($uri, ['siteId' => $siteId])->getBody();
        return $body['id'];
    }

    public function uploadRecursive(string $driveId, string $relativePath): Iterator
    {
        // Upload file structure, folders are created automatically
        $finder = new Finder();
        foreach ($finder->files()->in($relativePath)->getIterator() as $item) {
            $localPath = $item->getPathname();
            $relativePath = '/' . $item->getRelativePath();
            $relativePath = $relativePath !== '/' ? $relativePath : '';
            $name = $item->getFilename();

            // API sometimes accidentally returns an error, retry!
            $retry = 3;
            while (true) {
                try {
                    $file = $this->uploadFile($driveId, $localPath, $relativePath, $name);
                    yield $file->getPath() => $file;
                    break;
                } catch (Throwable $e) {
                    // Delete file, can be partially uploaded
                    if ($retry === 3) {
                        $this->delete($driveId, $relativePath . '/' . $name);
                    }

                    if ($retry-- <= 0) {
                        throw $e;
                    }
                }
            }
        }
    }

    public function uploadTmpFile(string $driveId, string $localPath, ?string $name = null): File
    {
        $localPath = FixturesCatalog::DATA_DIR . '/' . ltrim($localPath, '/');
        [$drivePath, $name] = self::createTmpFilePath($name);
        return $this->uploadFile($driveId, $localPath, $drivePath, $name);
    }

    public function uploadFile(string $driveId, string $localPath, string $drivePath, string $name): File
    {
        // The size of each byte range MUST be a multiple of 320 KiB
        // https://docs.microsoft.com/cs-cz/graph/api/driveitem-createuploadsession?view=graph-rest-1.0#upload-bytes-to-the-upload-session
        $uploadFragSize = 320 * 1024 * 10; // 3.2 MiB
        $fileSize = filesize($localPath);
        $path = $drivePath . '/' . $name;
        $url = $this->api->pathToUrl($driveId, $drivePath . '/' . $name);

        // Create upload session
        /** @var Model\UploadSession $uploadSession */
        $uploadSession = $this
            ->api
            ->getGraph()
            ->createRequest('POST', $url . 'createUploadSession')
            ->attachBody(['@microsoft.graph.conflictBehavior'=> 'replace' ])
            ->setReturnType(Model\UploadSession::class)
            ->setTimeout('1000')
            ->execute();

        // Upload file in parts
        $file = fopen($localPath, 'r');
        if (!$file) {
            throw new RuntimeException(sprintf('Cannot open file "%s".', $localPath));
        }

        try {
            while (!feof($file)) {
                $start = ftell($file);
                $data = fread($file, $uploadFragSize);
                $end = ftell($file);
                $uploadSession = $this
                    ->api
                    ->getGraph()
                    ->createRequest('PUT', $uploadSession->getUploadUrl())
                    ->addHeaders([
                        'Content-Length' => $end - $start,
                        'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end-1, $fileSize),
                    ])
                    ->attachBody($data)
                    ->setReturnType(Model\UploadSession::class)
                    ->setTimeout('1000')
                    ->execute();
            }
        } finally {
            fclose($file);
        }

        // Uploaded
        $fileId = $uploadSession->getId();
        FixturesUtils::log(sprintf('"%s" - uploaded', $path));

        // Create sharing link (for search by url tests)
        $linkBody = $this->api->post($url . 'createLink', ['type' => 'view', 'scope' => 'organization'])->getBody();
        $sharingLink = $linkBody['link']['webUrl'];
        FixturesUtils::log(sprintf('"%s" - created sharing link', $path));

        // Load worksheets if XLSX file
        $worksheets = iterator_to_array($this->loadWorksheets($path, $driveId, $fileId));
        return new File($path, $driveId, $fileId, $sharingLink, $worksheets);
    }

    public function delete(string $driveId, string $path): bool
    {
        try {
            $this->api->delete($this->api->pathToUrl($driveId, $path));
            FixturesUtils::log(sprintf('"%s" - deleted', $path));
            return true;
        } catch (ClientException $e) {
            return false;
        }
    }

    public function getWorksheetContent(File $file, int $worksheet): WorksheetContent
    {
        $endpoint = '/drives/{driveId}/items/{fileId}/workbook/worksheets/{worksheetId}';
        $uri = $endpoint . '/usedRange(valuesOnly=true)?$select=address,text';
        $args = [
            'driveId' => $file->getDriveId(),
            'fileId' => $file->getFileId(),
            'worksheetId' => $file->getWorksheetId($worksheet)]
        ;

        // Get rows
        $body = $this->api->get(Helpers::replaceParamsInUri($uri, $args))->getBody();
        $rows = $body['text'];

        // If empty file, empty first cell is returned
        $empty = isset($rows[0][0]) && count($rows) === 1 && count($rows[0]) === 1 && $rows[0][0] === '';
        $rows = $empty ? [] : $rows;

        return new WorksheetContent($body['address'], $rows);
    }

    public function loadWorksheets(string $path, string $driveId, string $fileId): Iterator
    {
        if (preg_match('~\.xlsx$~', $path)) {
            $uri = '/drives/{driveId}/items/{fileId}/workbook/worksheets?$select=id,position';
            $args = ['driveId' => $driveId, 'fileId' => $fileId];
            $body = $worksheetsResponse = $this->api->get($uri, $args)->getBody();
            foreach ($body['value'] as $item) {
                yield $item['position'] => $item['id'];
            }
            FixturesUtils::log(sprintf('"%s" - loaded worksheet ids', $path));
        }
    }
}
