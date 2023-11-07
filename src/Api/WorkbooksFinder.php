<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api;

use GuzzleHttp\Exception\RequestException;
use Keboola\OneDriveWriter\Api\Model\File;
use Keboola\OneDriveWriter\Exception\BadRequestException;
use Keboola\OneDriveWriter\Exception\FileInDriveNotFoundException;
use Keboola\OneDriveWriter\Exception\InvalidFileTypeException;
use Keboola\OneDriveWriter\Exception\ResourceNotFoundException;
use Keboola\OneDriveWriter\Exception\ShareLinkException;
use Keboola\OneDriveWriter\Exception\UnexpectedValueException;
use Keboola\OneDriveWriter\Exception\UserException;
use Psr\Log\LoggerInterface;

class WorkbooksFinder
{
    public const ALLOWED_MIME_TYPES = [
        # Only XLSX files can by accessed through API
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private Api $api;

    private LoggerInterface $logger;

    public function __construct(Api $api, LoggerInterface $logger)
    {
        $this->api = $api;
        $this->logger = $logger;
    }

    public function search(string $search): File
    {
        switch (true) {
            // Drive path, eg. "/path/to/file.xlsx"
            case Helpers::isFilePath($search):
                $this->log('Searching for "%s" in personal OneDrive.', $search);
                return $this->searchByPathInMeDrive($search);

            // Site path, eg. "drive://1234driveId6789/path/to/file.xlsx"
            case Helpers::isDriveFilePath($search):
                [$driveId, $path] = Helpers::explodeDriveFilePath($search);
                $this->log(
                    'Searching for "%s" in drive "%s".',
                    $path,
                    Helpers::truncate($driveId, 15)
                );
                return $this->searchByPathInDrive('/drives/' . urlencode($driveId), $path, []);

            // Site path, eg. "site://Excel Sheets/path/to/file.xlsx"
            case Helpers::isSiteFilePath($search):
                [$siteName, $path] = Helpers::explodeSiteFilePath($search);
                $this->log('Searching for "%s" in site "%s".', $path, $siteName);
                return $this->searchByPathInSite($siteName, $path);

            // Https url, eg: "https://keboolads.sharepoint.com/..."
            case Helpers::isHttpsUrl($search):
                $this->log('Searching by link "%s".', Helpers::truncate($search, 20));
                return $this->searchByUrl($search);

            // Search for file by text in all locations
            default:
                throw new UnexpectedValueException(sprintf('Unexpected path format "%s".', $search));
        }
    }

    private function searchByPathInMeDrive(string $path): File
    {
        return $this->searchByPathInDrive('/me/drive', $path, ['my']);
    }

    private function searchByPathInSite(string $siteName, string $path): File
    {
        $site = $this->api->getSite($siteName);
        $prefix = '/sites/' . urlencode($site->getId()) .  '/drive';
        return $this->searchByPathInDrive($prefix, $path, ['sites', $siteName]);
    }

    private function searchByPathInDrive(string $drivePrefix, string $path, array $pathPrefix): File
    {
        $graphPath = Helpers::convertPathToApiFormat($path);
        $endpoint = "{$drivePrefix}/root{$graphPath}";
        $url = "{$endpoint}?\$select=id,name,parentReference,file";
        try {
            $body = $this->api->get($url)->getBody();
        } catch (ResourceNotFoundException $e) {
            $msg = 'File "%s" not found in "%s".';
            throw new FileInDriveNotFoundException(sprintf($msg, $path, $drivePrefix), $endpoint, 0, $e);
        }

        // Check mime type
        self::checkFileMimeType($body);

        // Convert to object
        return File::from($body, $pathPrefix);
    }

    private function searchByUrl(string $url): File
    {
        // See: https://docs.microsoft.com/en-ca/onedrive/developer/rest-api/api/shares_get#encoding-sharing-urls
        $encode = base64_encode($url);
        $sharingUrl = 'u!' . str_replace('+', '-', str_replace('/', '_', rtrim($encode, '=')));

        // Get URL info and extract driveId, fileId
        try {
            $body = $this->api->get(sprintf('/shares/%s/driveItem', $sharingUrl))->getBody();
        } catch (RequestException|UserException $e) {
            /** @var RequestException $exception */
            $exception = $e instanceof UserException ? $e->getPrevious() : $e;
            $error = Helpers::getErrorFromRequestException($exception);
            switch (true) {
                // Not exists
                case $error && strpos($error, 'AccessDenied: The sharing link no longer exists') === 0:
                    throw new ShareLinkException(sprintf(
                        'The sharing link "%s..." not exists, or you do not have permission to access it.',
                        substr($url, 0, 32)
                    ), 0, $e);

                // Access denied
                case $error && strpos($error, 'AccessDenied:') === 0:
                    throw new ShareLinkException(sprintf(
                        'The sharing link "%s..." not exists, or you do not have permission to access it.',
                        substr($url, 0, 32)
                    ), 0, $e);

                // Invalid link
                case $error === 'InvalidRequest: The sharing token is invalid.':
                case $error === 'InvalidRequest: The site in the encoded share URI is invalid.':
                    throw new ShareLinkException(sprintf(
                        'The sharing link "%s..." is invalid.',
                        substr($url, 0, 32)
                    ), 0, $e);

                default:
                    throw $e;
            }
        } catch (BadRequestException $e) {
            throw new ShareLinkException(sprintf(
                'The sharing link "%s..." is invalid.',
                substr($url, 0, 32)
            ), 0, $e);
        }

        // Check mime type
        self::checkFileMimeType($body);

        // Convert to object
        return  File::from($body, []);
    }

    /**
     * @param mixed ...$args args for sprintf
     */
    private function log(...$args): void
    {
        $this->logger->info(sprintf(...$args));
    }

    private static function checkFileMimeType(array $body): void
    {
        $mimeType = $body['file']['mimeType'] ?? 'undefined-mime-type';
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidFileTypeException(sprintf(
                'File is not in the "XLSX" Excel format. Mime type: "%s"',
                $mimeType
            ));
        }
    }
}
