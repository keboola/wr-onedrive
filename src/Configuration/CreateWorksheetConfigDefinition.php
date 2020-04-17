<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Configuration;

use Keboola\Component\Config\BaseConfigDefinition;
use Keboola\OneDriveWriter\Configuration\Parts\WorkbookDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class CreateWorksheetConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('parameters');
        /** @var ArrayNodeDefinition $parametersNode */
        $parametersNode = $builder->getRootNode();

        // @formatter:off
        $parametersNode
            ->children()
                // Workbook is one XLSX file
                ->append(WorkbookDefinition::getDefinition())
                // Worksheet is one sheet from workbook's sheets
                ->arrayNode('worksheet')
                    ->isRequired()
                    ->children()
                        // To create new worksheet, must be specified by name
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                    ->end()
            ->end();
        // @formatter:on

        return $parametersNode;
    }
}
