<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter;

use ArrayObject;
use Keboola\OneDriveWriter\Api\ApiFactory;
use Keboola\OneDriveWriter\Auth\TokenProviderFactory;
use Keboola\OneDriveWriter\Configuration\CreateWorkbookConfigDefinition;
use Keboola\OneDriveWriter\Configuration\CreateWorksheetConfigDefinition;
use Keboola\OneDriveWriter\Configuration\SyncActionConfigDefinition;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;
use Keboola\OneDriveWriter\Exception\ResourceNotFoundException;
use Keboola\OneDriveWriter\Api\Api;
use Keboola\Component\BaseComponent;
use Keboola\OneDriveWriter\Configuration\Config;
use Keboola\OneDriveWriter\Configuration\ConfigDefinition;

class Component extends BaseComponent
{
    public const ACTION_RUN = 'run';
    public const ACTION_SEARCH = 'search';
    public const ACTION_GET_WORKSHEETS = 'getWorksheets';
    public const ACTION_CREATE_WORKBOOK = 'createWorkbook';
    public const ACTION_CREATE_WORKSHEET = 'createWorksheet';

    private ArrayObject $stateObject;

    private Api $api;

    private SheetProvider $sheetProvider;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
        $config = $this->getConfig();
        $this->stateObject = new ArrayObject($this->getInputState());

        $tokenProviderFactory = new TokenProviderFactory($config, $this->stateObject);
        $tokenProvider = $tokenProviderFactory->create();
        $apiFactory = new ApiFactory($logger, $tokenProvider);
        $this->api = $apiFactory->create();
        $this->sheetProvider = new SheetProvider($this->api, $this->getConfig());
    }

    public function execute(): void
    {
        try {
            parent::execute();
        } finally {
            $this->writeOutputStateToFile($this->stateObject->getArrayCopy());
        }
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
            self::ACTION_CREATE_WORKBOOK => 'handleCreateWorkbookSyncAction',
            self::ACTION_CREATE_WORKSHEET => 'handleCreateWorksheetSyncAction',
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

    protected function handleCreateWorkbookSyncAction(): array
    {
        $file = $this->sheetProvider->createFile();
        return [
            'file' => $file,
        ];
    }

    protected function handleCreateWorksheetSyncAction(): array
    {
        $sheet = $this->sheetProvider->createSheet();
        return [
            'worksheet' => $sheet,
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
            case self::ACTION_CREATE_WORKBOOK:
                return CreateWorkbookConfigDefinition::class;
            case self::ACTION_CREATE_WORKSHEET:
                return CreateWorksheetConfigDefinition::class;
            case self::ACTION_SEARCH:
            case self::ACTION_GET_WORKSHEETS:
                return SyncActionConfigDefinition::class;
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
