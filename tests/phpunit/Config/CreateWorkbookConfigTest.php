<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests\Config;

use Keboola\OneDriveWriter\Configuration\Config;
use Keboola\OneDriveWriter\Configuration\CreateWorkbookConfigDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class CreateWorkbookConfigTest extends BaseConfigTest
{
    /**
     * @dataProvider validConfigProvider
     */
    public function testValidConfig(array $config): void
    {
        new Config($config, new CreateWorkbookConfigDefinition());
        $this->addToAssertionCount(1); // Assert no error
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function testInvalidConfig(string $expectedMsg, array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMsg);
        new Config($config, new CreateWorkbookConfigDefinition());
    }

    public function validConfigProvider(): array
    {
        return [
            'valid-path' => [
                [
                    'action' => 'createWorkbook',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function invalidConfigProvider(): array
    {
        return [
            'empty' => [
                'Missing OAuth credentials, ' .
                'please set "authorization.oauth_api.credentials.{appKey,#appSecret,#data}".',
                [
                    'action' => 'createWorkbook',
                ],
            ],
            'missing-authorization' => [
                'Missing OAuth credentials, ' .
                'please set "authorization.oauth_api.credentials.{appKey,#appSecret,#data}".',
                [
                    'action' => 'createWorkbook',
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                        ],
                    ],
                ],
            ],
            'missing-workbook' => [
                'The child node "workbook" at path "root.parameters" must be configured.',
                [
                    'action' => 'createWorkbook',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [

                    ],
                ],
            ],
            'ids' => [
                'Unrecognized options "driveId, fileId" under "root.parameters.workbook". Available option is "path".',
                [
                    'action' => 'createWorkbook',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '...',
                            'fileId' => '...',
                        ],
                    ],
                ],
            ],
            'drive-id' => [
                'Unrecognized option "driveId" under "root.parameters.workbook". Available option is "path".',
                [
                    'action' => 'createWorkbook',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                            'driveId' => '1234abc',
                        ],
                    ],
                ],
            ],
            'file-id' => [
                'Unrecognized option "fileId" under "root.parameters.workbook". Available option is "path".',
                [
                    'action' => 'createWorkbook',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                            'fileId' => '1234abc',
                        ],
                    ],
                ],
            ],
        ];
    }
}
