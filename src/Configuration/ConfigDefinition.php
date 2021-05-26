<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Configuration;

use Keboola\Component\Config\BaseConfigDefinition;
use Keboola\OneDriveWriter\Configuration\Parts\WorkbookDefinition;
use Keboola\OneDriveWriter\Configuration\Parts\WorksheetDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class ConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('parameters');
        /** @var ArrayNodeDefinition $parametersNode */
        $parametersNode = $builder->getRootNode();

        // @formatter:off
        $parametersNode
            ->children()
                ->booleanNode('append')->defaultValue(false)->end()
                ->integerNode('bulkSize')->defaultValue(10000)->end()
                // Workbook is one XLSX file
                ->append(WorkbookDefinition::getDefinition())
                // In one workbook are multiple worksheets, specify one
                ->append(WorksheetDefinition::getDefinition())
            ->end();
        // @formatter:on

        return $parametersNode;
    }
}
