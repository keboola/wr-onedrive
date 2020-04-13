<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests\Config;

use Keboola\OneDriveWriter\Configuration\Config;
use Keboola\OneDriveWriter\Configuration\ConfigDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class RunConfigTest extends BaseConfigTest
{
    /**
     * @dataProvider validConfigProvider
     */
    public function testValidConfig(array $config): void
    {
        new Config($config, new ConfigDefinition());
        $this->addToAssertionCount(1); // Assert no error
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function testInvalidConfig(string $expectedMsg, array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMsg);
        new Config($config, new ConfigDefinition());
    }

    public function validConfigProvider(): array
    {
        return [
            'valid-path-position' => [
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'valid-file-id' => [
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                            'fileId' => '5678def',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'valid-worksheet-name' => [
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                            'fileId' => '5678def',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                        ],
                    ],
                ],
            ],
            'valid-worksheet-id' => [
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                            'fileId' => '5678def',
                        ],
                        'worksheet' => [
                            'id' => '9012xyz',
                        ],
                    ],
                ],
            ],
            'valid-worksheet-id-name' => [
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                            'fileId' => '5678def',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'id' => '9012xyz',
                        ],
                    ],
                ],
            ],
            'valid-worksheet-position' => [
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                            'fileId' => '5678def',
                        ],
                        'worksheet' => [
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'valid-worksheet-position-name' => [
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                            'fileId' => '5678def',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'valid-default-bucket' => [
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                            'fileId' => '5678def',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'id' => '9012xyz',
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
                [],
            ],
            'missing-authorization' => [
                'Missing OAuth credentials, ' .
                'please set "authorization.oauth_api.credentials.{appKey,#appSecret,#data}".',
                [
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'missing-workbook' => [
                'The child node "workbook" at path "root.parameters" must be configured.',
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'missing-worksheet' => [
                'The child node "worksheet" at path "root.parameters" must be configured.',
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                        ],
                    ],
                ],
            ],
            'missing-file-id' => [
                'Both "workbook.driveId" and "workbook.fileId" must be configured.',
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'missing-drive-id' => [
                'Both "workbook.driveId" and "workbook.fileId" must be configured.',
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'fileId' => '1234abc',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'extra-workbook-path-key' => [
                'In config is present "workbook.path", ' .
                'therefore "workbook,driveId" and "workbook.fileId" are not expected.',
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                            'driveId' => '1234abc',
                            'fileId' => '4567def',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
            'extra-worksheet-position' => [
                'In config must be ONLY ONE OF "worksheet.id" OR "worksheet.position". Both given.',
                [
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                            'fileId' => '4567def',
                        ],
                        'worksheet' => [
                            'name' => 'Sheet 1',
                            'id' => '901xyz',
                            'position' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }
}
