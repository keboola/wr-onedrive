<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests\Config;

use Keboola\OneDriveWriter\Configuration\Actions\SearchConfigDefinition;
use Keboola\OneDriveWriter\Configuration\Config;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class SearchConfigTest extends BaseConfigTest
{
    /**
     * @dataProvider validConfigProvider
     */
    public function testValidConfig(array $config): void
    {
        new Config($config, new SearchConfigDefinition());
        $this->addToAssertionCount(1); // Assert no error
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function testInvalidConfig(string $expectedMsg, array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMsg);
        new Config($config, new SearchConfigDefinition());
    }

    public function validConfigProvider(): array
    {
        return [
            'valid-path' => [
                [
                    'action' => 'path',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                        ],
                    ],
                ],
            ],
            'valid-ids' => [
                [
                    'action' => 'path',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '...',
                            'fileId' => '...',
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
                    'action' => 'getWorksheets',
                ],
            ],
            'missing-authorization' => [
                'Missing OAuth credentials, ' .
                'please set "authorization.oauth_api.credentials.{appKey,#appSecret,#data}".',
                [
                    'action' => 'getWorksheets',
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
                    'action' => 'getWorksheets',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [

                    ],
                ],
            ],
            'missing-file-id' => [
                'Both "workbook.driveId" and "workbook.fileId" must be configured.',
                [
                    'action' => 'getWorksheets',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '1234abc',
                        ],
                    ],
                ],
            ],
            'missing-drive-id' => [
                'Both "workbook.driveId" and "workbook.fileId" must be configured.',
                [
                    'action' => 'getWorksheets',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'fileId' => '1234abc',
                        ],
                    ],
                ],
            ],
            'extra-workbook-path-key' => [
                'In config is present "workbook.path", ' .
                'therefore "workbook,driveId" and "workbook.fileId" are not expected.',
                [
                    'action' => 'getWorksheets',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                            'driveId' => '1234abc',
                            'fileId' => '4567def',
                        ],
                    ],
                ],
            ],
        ];
    }
}