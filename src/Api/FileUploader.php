<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api;

use RuntimeException;
use Microsoft\Graph\Model;
use Keboola\OneDriveWriter\Api\Model\File;

class FileUploader
{
    private Api $api;

    public function __construct(Api $api)
    {
        $this->api = $api;
    }

    public function upload(string $endpoint, string $localPath): File
    {
        // The size of each byte range MUST be a multiple of 320 KiB
        // https://docs.microsoft.com/cs-cz/graph/api/driveitem-createuploadsession?view=graph-rest-1.0#upload-bytes-to-the-upload-session
        $uploadFragSize = 320 * 1024 * 10; // 3.2 MiB
        $fileSize = filesize($localPath);

        // Create upload session
        $uploadSession = $this->createUploadSession($endpoint);

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
                        'Authorization' => '',
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
        return File::from($uploadSession->getProperties());
    }

    private function createUploadSession(string $url): Model\UploadSession
    {
        $uploadSession = $this
            ->api
            ->getGraph()
            ->createRequest('POST', $url . 'createUploadSession')
            ->attachBody(['@microsoft.graph.conflictBehavior'=> 'replace' ])
            ->setReturnType(Model\UploadSession::class)
            ->setTimeout('1000')
            ->execute();
        assert($uploadSession instanceof Model\UploadSession);
        return $uploadSession;
    }
}
