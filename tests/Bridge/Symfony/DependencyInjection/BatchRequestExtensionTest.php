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

namespace Lemric\BatchRequest\Tests\Bridge\Symfony\DependencyInjection;

use Lemric\BatchRequest\Bridge\Symfony\{SymfonyBatchRequestFacade, SymfonyTransactionExecutor};
use Lemric\BatchRequest\Bridge\Symfony\DependencyInjection\BatchRequestExtension;
use Lemric\BatchRequest\Bridge\Symfony\Profiler\{BatchRequestDataCollector, TraceableTransactionExecutor};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class BatchRequestExtensionTest extends TestCase
{
    public function testProfilerServicesNotRegisteredWhenDisabled(): void
    {
        $container = $this->buildContainer(debug: true, config: ['profiler' => false]);

        self::assertFalse($container->hasDefinition('lemric_batch_request.profiler.traceable_executor'));
        self::assertFalse($container->hasDefinition('lemric_batch_request.profiler.data_collector'));
    }

    public function testRegistersProfilerServicesWhenDebugEnabled(): void
    {
        $container = $this->buildContainer(debug: true);

        self::assertTrue($container->hasDefinition('lemric_batch_request.profiler.traceable_executor'));
        self::assertTrue($container->hasDefinition('lemric_batch_request.profiler.data_collector'));

        $traceable = $container->getDefinition('lemric_batch_request.profiler.traceable_executor');
        self::assertSame(TraceableTransactionExecutor::class, $traceable->getClass());
        self::assertArrayHasKey('kernel.reset', $traceable->getTags());

        $collector = $container->getDefinition('lemric_batch_request.profiler.data_collector');
        self::assertSame(BatchRequestDataCollector::class, $collector->getClass());
        $tags = $collector->getTag('data_collector');
        self::assertNotEmpty($tags);
        self::assertSame(BatchRequestDataCollector::NAME, $tags[0]['id']);
        self::assertStringContainsString('batch_request.html.twig', (string) $tags[0]['template']);
    }

    public function testRegistersServicesAndAppliesConfig(): void
    {
        $container = $this->buildContainer(debug: false, config: [
            'max_batch_size' => 10,
            'max_concurrency' => 4,
            'max_transaction_content_length' => 1024,
            'forwarded_headers_whitelist' => ['x-trace-id'],
        ]);

        self::assertTrue($container->hasDefinition(SymfonyTransactionExecutor::class));
        self::assertTrue($container->hasDefinition(SymfonyBatchRequestFacade::class));
        self::assertSame(10, $container->getParameter('lemric_batch_request.max_batch_size'));
        self::assertSame(4, $container->getParameter('lemric_batch_request.max_concurrency'));
        self::assertSame(1024, $container->getParameter('lemric_batch_request.max_transaction_content_length'));
        self::assertSame(['x-trace-id'], $container->getParameter('lemric_batch_request.forwarded_headers_whitelist'));

        $facade = $container->getDefinition(SymfonyBatchRequestFacade::class);
        self::assertSame(10, $facade->getArgument('$maxBatchSize'));
        self::assertSame(['x-trace-id'], $facade->getArgument('$forwardedHeadersWhitelist'));
    }

    public function testTraceableExecutorIsInjectedIntoFacadeWhenProfilerEnabled(): void
    {
        $container = $this->buildContainer(debug: true);

        $facade = $container->getDefinition(SymfonyBatchRequestFacade::class);
        $arg = $facade->getArgument('$transactionExecutor');
        self::assertNotNull($arg);
        self::assertSame('lemric_batch_request.profiler.traceable_executor', (string) $arg);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildContainer(bool $debug, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setDefinition(HttpKernelInterface::class, new Definition(HttpKernelInterface::class));
        $container->setDefinition('logger', new Definition(\Psr\Log\NullLogger::class));

        $extension = new BatchRequestExtension();
        $extension->load([$config], $container);

        return $container;
    }
}

