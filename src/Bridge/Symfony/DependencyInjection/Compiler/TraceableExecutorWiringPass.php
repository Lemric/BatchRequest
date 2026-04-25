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

namespace Lemric\BatchRequest\Bridge\Symfony\DependencyInjection\Compiler;

use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Forces every {@see SymfonyBatchRequestFacade} definition (including
 * user-supplied ones declared in `services.yaml` / `services.php`) to
 * receive the traceable transaction executor when the profiler
 * integration is enabled.
 *
 * Without this pass, an application that re-declares the facade in its
 * own service file – e.g. to bind a rate limiter – would shadow the
 * argument wired by {@see \Lemric\BatchRequest\Bridge\Symfony\DependencyInjection\BatchRequestExtension}
 * and the `BatchRequestDataCollector` would always report an empty
 * batch.
 *
 * Runs as a compiler pass (i.e. after every extension has loaded its
 * configuration), so it can correct definitions registered by the user
 * after the bundle. It explicitly skips definitions whose
 * `$transactionExecutor` argument has been set to a non-null value by
 * the user, thereby honouring an opt-out.
 */
final class TraceableExecutorWiringPass implements CompilerPassInterface
{
    private const TRACEABLE_EXECUTOR_ID = 'lemric_batch_request.profiler.traceable_executor';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::TRACEABLE_EXECUTOR_ID)) {
            return;
        }

        $traceable = new Reference(
            self::TRACEABLE_EXECUTOR_ID,
            ContainerInterface::IGNORE_ON_INVALID_REFERENCE,
        );

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? (is_string($id) ? $id : '');

            if (
                SymfonyBatchRequestFacade::class !== $class
                && !is_subclass_of($class, SymfonyBatchRequestFacade::class)
            ) {
                continue;
            }

            $arguments = $definition->getArguments();

            // Honour an explicit, non-null user override.
            if (
                array_key_exists('$transactionExecutor', $arguments)
                && null !== $arguments['$transactionExecutor']
            ) {
                continue;
            }

            $definition->setArgument('$transactionExecutor', $traceable);
        }
    }
}

