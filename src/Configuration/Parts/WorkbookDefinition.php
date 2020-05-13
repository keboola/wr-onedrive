<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Configuration\Parts;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class WorkbookDefinition
{
    public static function getDefinition(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('workbook');

        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        // @formatter:off
        $root
            ->isRequired()
            ->children()
                // Workbook is specified by driveId, fileId
                ->scalarNode('driveId')->cannotBeEmpty()->end()
                ->scalarNode('fileId')->cannotBeEmpty()->end()
                // ... OR by search (path, download url, ...)
                ->scalarNode('path')->cannotBeEmpty()->end()
                // optional metadata can be always present, it is not used in code
                ->arrayNode('metadata')->ignoreExtraKeys(true)->end()
            ->end()
            // Not empty
            ->validate()
                ->ifTrue(function (array $workbook): bool {
                    return !isset($workbook['path']) && !isset($workbook['driveId']) && !isset($workbook['fileId']);
                })
                ->thenInvalid(
                    'In config must be present "workbook.path" OR ' .
                    '("workbook.driveId" and "workbook.fileId").'
                )
            ->end()
            // Must be present "workbook.search" OR ("workbook,driveId" and "workbook.fileId") - not both
            ->validate()
                ->ifTrue(function (array $workbook): bool {
                    return isset($workbook['path']) && (isset($workbook['driveId']) || isset($workbook['fileId']));
                })
                ->thenInvalid(
                    'In config is present "workbook.path", ' .
                    'therefore "workbook,driveId" and "workbook.fileId" are not expected.'
                )
            ->end()
            // If is one of driveId/fileId set, check both are set
            ->validate()
                ->ifTrue(function (array $workbook): bool {
                    return isset($workbook['driveId']) xor isset($workbook['fileId']);
                })
                ->thenInvalid(
                    'Both "workbook.driveId" and "workbook.fileId" must be configured.'
                )
            ->end();
        // @formatter:on

        return $root;
    }
}
