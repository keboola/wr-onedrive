<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter;

use Psr\Log\LoggerInterface;
use UnexpectedValueException;
use Keboola\OneDriveWriter\Exception\ResourceNotFoundException;
use Keboola\OneDriveWriter\Api\Api;
use Keboola\OneDriveWriter\Api\ApiFactory;
use Keboola\Component\BaseComponent;
use Keboola\OneDriveWriter\Configuration\Config;
use Keboola\OneDriveWriter\Configuration\Actions\SearchConfigDefinition;
use Keboola\OneDriveWriter\Configuration\Actions\GetWorksheetsConfigDefinition;
use Keboola\OneDriveWriter\Configuration\ConfigDefinition;

class Component extends BaseComponent
{
    public const ACTION_RUN = 'run';
    public const ACTION_SEARCH = 'search';
    public const ACTION_GET_WORKSHEETS = 'getWorksheets';

    private Api $api;

    private SheetProvider $sheetProvider;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
        $config = $this->getConfig();
        $apiFactory = new ApiFactory($logger);
        $this->api = $apiFactory->create(
            $config->getOAuthApiAppKey(),
            $config->getOAuthApiAppSecret(),
            $config->getOAuthApiData()
        );
        $this->sheetProvider = new SheetProvider($this->api, $this->getConfig());
    }

    public function getConfig(): Config
    {
        $config = parent::getConfig();
        assert($config instanceof Config);
        return $config;
    }

    protected function getSyncActions(): array
    {
        return [
            self::ACTION_SEARCH => 'handleSearchSyncAction',
            self::ACTION_GET_WORKSHEETS => 'handleGetWorksheetsSyncAction',
        ];
    }

    protected function run(): void
    {
        $sheet = $this->sheetProvider->getSheet();
        $this->createWriter()->write($sheet);
    }

    protected function handleSearchSyncAction(): array
    {
        try {
            $file = $this->api->searchWorkbook($this->getConfig()->getPath());
        } catch (ResourceNotFoundException $e) {
            $file = null;
        }

        return [
            'file' => $file,
        ];
    }

    protected function handleGetWorksheetsSyncAction(): array
    {
        $workbook = $this->sheetProvider->getFile();
        $worksheets = iterator_to_array($this->api->getSheets($workbook->getDriveId(), $workbook->getFileId()));
        return [
            'worksheets' => $worksheets,
        ];
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        $action = $this->getRawConfig()['action'] ?? 'run';
        switch ($action) {
            case self::ACTION_RUN:
                return ConfigDefinition::class;
            case self::ACTION_SEARCH:
                return SearchConfigDefinition::class;
            case self::ACTION_GET_WORKSHEETS:
                return GetWorksheetsConfigDefinition::class;
            default:
                throw new UnexpectedValueException(sprintf('Unexpected action "%s"', $action));
        }
    }

    private function createWriter(): Writer
    {
        return new Writer(
            $this->getLogger(),
            $this->api,
            $this->getDataDir(),
            $this->getConfig(),
        );
    }
}
