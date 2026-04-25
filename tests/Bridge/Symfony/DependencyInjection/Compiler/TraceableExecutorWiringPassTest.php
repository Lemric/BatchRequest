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

namespace Lemric\BatchRequest\Tests\Bridge\Symfony\DependencyInjection\Compiler;

use Lemric\BatchRequest\Bridge\Symfony\DependencyInjection\BatchRequestExtension;
use Lemric\BatchRequest\Bridge\Symfony\DependencyInjection\Compiler\TraceableExecutorWiringPass;
use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TraceableExecutorWiringPassTest extends TestCase
{
    public function testHonoursExplicitNonNullUserOverride(): void
    {
        $container = $this->buildContainer(debug: true);
        $container->getDefinition(SymfonyBatchRequestFacade::class)
            ->setArgument('$transactionExecutor', new Reference('app.custom_executor'));

        (new TraceableExecutorWiringPass())->process($container);

        $arg = $container->getDefinition(SymfonyBatchRequestFacade::class)
            ->getArgument('$transactionExecutor');

        self::assertInstanceOf(Reference::class, $arg);
        self::assertSame('app.custom_executor', (string) $arg);
    }

    public function testInjectsTraceableExecutorWhenArgumentMissing(): void
    {
        $container = $this->buildContainer(debug: true);
        $container->getDefinition(SymfonyBatchRequestFacade::class)
            ->setArgument('$transactionExecutor', null);

        (new TraceableExecutorWiringPass())->process($container);

        $arg = $container->getDefinition(SymfonyBatchRequestFacade::class)
            ->getArgument('$transactionExecutor');

        self::assertInstanceOf(Reference::class, $arg);
        self::assertSame('lemric_batch_request.profiler.traceable_executor', (string) $arg);
    }

    public function testIsNoOpWhenProfilerDisabled(): void
    {
        $container = $this->buildContainer(debug: false, config: ['profiler' => false]);

        (new TraceableExecutorWiringPass())->process($container);

        self::assertFalse($container->hasDefinition('lemric_batch_request.profiler.traceable_executor'));
    }

    public function testPatchesUserSuppliedFacadeDefinition(): void
    {
        $container = $this->buildContainer(debug: true);

        // Simulate a user-defined service overriding the bundle's facade
        // — exactly the situation reported in the bug, where the user
        // forgot to wire $transactionExecutor.
        $userDef = (new Definition(SymfonyBatchRequestFacade::class))
            ->setArguments([
                '$httpKernel' => new Reference(HttpKernelInterface::class),
                '$rateLimiterFactory' => null,
                '$logger' => new Reference('logger'),
                '$maxBatchSize' => 25,
            ])
            ->setPublic(true);
        $container->setDefinition(SymfonyBatchRequestFacade::class, $userDef);

        (new TraceableExecutorWiringPass())->process($container);

        $arg = $container->getDefinition(SymfonyBatchRequestFacade::class)
            ->getArgument('$transactionExecutor');

        self::assertInstanceOf(Reference::class, $arg);
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
        $container->setDefinition('logger', new Definition(NullLogger::class));

        (new BatchRequestExtension())->load([$config], $container);

        return $container;
    }
}

