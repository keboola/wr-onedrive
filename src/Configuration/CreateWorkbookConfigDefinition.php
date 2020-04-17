<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Configuration;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class CreateWorkbookConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('parameters');
        /** @var ArrayNodeDefinition $parametersNode */
        $parametersNode = $builder->getRootNode();

        // @formatter:off
        $parametersNode
            ->children()
                ->arrayNode('workbook')
                    ->isRequired()
                    ->children()
                        // To create new workbook, must be specified by path
                        ->scalarNode('path')->isRequired()->cannotBeEmpty()->end()
                    ->end()
            ->end();
        // @formatter:on

        return $parametersNode;
    }
}
