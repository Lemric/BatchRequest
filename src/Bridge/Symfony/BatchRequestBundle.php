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

namespace Lemric\BatchRequest\Bridge\Symfony;

use Lemric\BatchRequest\Bridge\Symfony\DependencyInjection\BatchRequestExtension;
use Lemric\BatchRequest\Bridge\Symfony\DependencyInjection\Compiler\TraceableExecutorWiringPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle wiring the batch-request facade and – when
 * `kernel.debug` is enabled – the profiler integration (data
 * collector + traceable transaction executor).
 *
 * Compatible with Symfony 6.x, 7.x and 8.x.
 */
final class BatchRequestBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Runs after extension load so it can patch user-defined
        // SymfonyBatchRequestFacade definitions that omit the traceable
        // executor argument.
        $container->addCompilerPass(new TraceableExecutorWiringPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new BatchRequestExtension();
        }

        return false === $this->extension ? null : $this->extension;
    }

    public function getPath(): string
    {
        return __DIR__;
    }
}

