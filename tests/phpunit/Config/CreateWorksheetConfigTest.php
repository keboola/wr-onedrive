<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests\Config;

use Keboola\OneDriveWriter\Configuration\Config;
use Keboola\OneDriveWriter\Configuration\CreateWorksheetConfigDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class CreateWorksheetConfigTest extends BaseConfigTest
{
    /**
     * @dataProvider validConfigProvider
     */
    public function testValidConfig(array $config): void
    {
        new Config($config, new CreateWorksheetConfigDefinition());
        $this->addToAssertionCount(1); // Assert no error
    }

    /**
     * @dataProvider invalidConfigProvider
     */
    public function testInvalidConfig(string $expectedMsg, array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMsg);
        new Config($config, new CreateWorksheetConfigDefinition());
    }

    public function validConfigProvider(): array
    {
        return [
            'valid-name' => [
                [
                    'action' => 'createWorksheet',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '...',
                            'fileId' => '...',
                        ],
                        'worksheet' => [
                            'name' => 'New Sheet 1',
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
                    'action' => 'createWorksheet',
                ],
            ],
            'missing-authorization' => [
                'Missing OAuth credentials, ' .
                'please set "authorization.oauth_api.credentials.{appKey,#appSecret,#data}".',
                [
                    'action' => 'createWorksheet',
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '...',
                            'fileId' => '...',
                        ],
                        'worksheet' => [
                            'name' => 'New Sheet 1',
                        ],
                    ],
                ],
            ],
            'missing-all' => [
                'The child node "workbook" at path "root.parameters" must be configured.',
                [
                    'action' => 'createWorksheet',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [

                    ],
                ],
            ],
            'missing-worksheet' => [
                'The child node "worksheet" at path "root.parameters" must be configured.',
                [
                    'action' => 'createWorksheet',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'path' => '/path/to/file',
                        ],
                    ],
                ],
            ],
            'worksheet-position' => [
                'Unrecognized option "position" under "root.parameters.worksheet". Available option is "name".',
                [
                    'action' => 'createWorksheet',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '...',
                            'fileId' => '...',
                        ],
                        'worksheet' => [
                            'position' => 4,
                            'name' => 'New Sheet 1',
                        ],
                    ],
                ],
            ],
            'worksheet-id' => [
                'Unrecognized option "id" under "root.parameters.worksheet". Available option is "name".',
                [
                    'action' => 'createWorksheet',
                    'authorization' => $this->getValidAuthorization(),
                    'parameters' => [
                        'workbook' => [
                            'driveId' => '...',
                            'fileId' => '...',
                        ],
                        'worksheet' => [
                            'id' => '...',
                            'name' => 'New Sheet 1',
                        ],
                    ],
                ],
            ],
        ];
    }
}
