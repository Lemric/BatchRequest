<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */
declare(strict_types=1);

namespace Lemric\BatchRequest\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Semantic configuration for the `lemric_batch_request` bundle.
 *
 * @see config/packages/lemric_batch_request.yaml
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('lemric_batch_request');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->integerNode('max_batch_size')
                    ->defaultValue(50)
                    ->min(1)
                ->end()
                ->integerNode('max_concurrency')
                    ->defaultValue(8)
                    ->min(1)
                ->end()
                ->integerNode('max_transaction_content_length')
                    ->defaultValue(262144)
                    ->min(1)
                ->end()
                ->arrayNode('forwarded_headers_whitelist')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('rate_limiter')
                    ->info('Service id of a Symfony RateLimiterFactory (optional).')
                    ->defaultNull()
                ->end()
                ->booleanNode('profiler')
                    ->info('Enable Symfony Profiler integration (defaults to kernel.debug).')
                    ->defaultNull()
                ->end()
            ->end();

        return $treeBuilder;
    }
}

