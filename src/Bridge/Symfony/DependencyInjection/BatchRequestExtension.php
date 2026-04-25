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

use Lemric\BatchRequest\Bridge\Symfony\{SymfonyBatchRequestFacade, SymfonyTransactionExecutor};
use Lemric\BatchRequest\Bridge\Symfony\Profiler\{BatchRequestDataCollector, TraceableTransactionExecutor};
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Loads the batch-request services and – on debug builds – wires the
 * Symfony Profiler data collector together with a traceable transaction
 * executor decorator.
 */
final class BatchRequestExtension extends Extension
{
    public function getAlias(): string
    {
        return 'lemric_batch_request';
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('lemric_batch_request.max_batch_size', $config['max_batch_size']);
        $container->setParameter('lemric_batch_request.max_concurrency', $config['max_concurrency']);
        $container->setParameter(
            'lemric_batch_request.max_transaction_content_length',
            $config['max_transaction_content_length'],
        );
        $container->setParameter(
            'lemric_batch_request.forwarded_headers_whitelist',
            $config['forwarded_headers_whitelist'],
        );

        $facade = $container->getDefinition(SymfonyBatchRequestFacade::class);
        $facade->setArgument('$maxBatchSize', $config['max_batch_size']);
        $facade->setArgument('$maxConcurrency', $config['max_concurrency']);
        $facade->setArgument('$maxTransactionContentLength', $config['max_transaction_content_length']);
        $facade->setArgument('$forwardedHeadersWhitelist', $config['forwarded_headers_whitelist']);

        if (null !== $config['rate_limiter']) {
            $facade->setArgument('$rateLimiterFactory', new Reference($config['rate_limiter']));
        }

        $debug = (bool) $container->getParameter('kernel.debug');
        $profilerEnabled = $config['profiler'] ?? $debug;

        if ($profilerEnabled) {
            $container->register(
                'lemric_batch_request.profiler.traceable_executor',
                TraceableTransactionExecutor::class,
            )
                ->setArguments([new Reference(SymfonyTransactionExecutor::class)])
                ->addTag('kernel.reset', ['method' => 'reset'])
                ->setPublic(false);

            $facade->setArgument(
                '$transactionExecutor',
                new Reference('lemric_batch_request.profiler.traceable_executor'),
            );

            $container->register(
                'lemric_batch_request.profiler.data_collector',
                BatchRequestDataCollector::class,
            )
                ->setArguments([new Reference('lemric_batch_request.profiler.traceable_executor')])
                ->addTag('data_collector', [
                    'id' => BatchRequestDataCollector::NAME,
                    'template' => '@BatchRequest/Collector/batch_request.html.twig',
                ])
                ->setPublic(false);
        }
    }
}


