<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Configuration\Parts;

use Keboola\OneDriveWriter\Exception\InvalidConfigException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class WorksheetDefinition
{
    public static function getDefinition(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('worksheet');

        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        // @formatter:off
        $root
            ->isRequired()
            ->children()
                // Worksheet is specified by name (name can be set always, also with id or position)
                ->scalarNode('name')->cannotBeEmpty()->end()
                // ... or id
                ->scalarNode('id')->cannotBeEmpty()->end()
                // ... or position
                ->scalarNode('position')->cannotBeEmpty()->end()
                // optional metadata can be always present, it is not used in code
                ->arrayNode('metadata')->ignoreExtraKeys(true)->end()
            ->end()
            // Only one of id/position allowed
            ->validate()
                ->ifTrue(function (array $worksheet): bool {
                    $hasId = isset($worksheet['id']);
                    $hasPosition = array_key_exists('position', $worksheet); // position can be 0
                    return $hasId && $hasPosition;
                })
                ->thenInvalid('In config must be ONLY ONE OF "worksheet.id" OR "worksheet.position". Both given.')
            ->end()
            // One of name/id/position must be set
            ->validate()
                ->ifTrue(function (array $worksheet): bool {
                    $hasName = isset($worksheet['name']);
                    $hasId = isset($worksheet['id']);
                    $hasPosition = array_key_exists('position', $worksheet); // position can be 0
                    return !$hasName && !$hasId && !$hasPosition;
                })
                ->thenInvalid('In config must be ONE OF "worksheet.name", "worksheet.id" OR "worksheet.position".')
            ->end();
        // @formatter:on

        return $root;
    }
}
