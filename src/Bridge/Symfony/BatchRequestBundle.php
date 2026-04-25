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

