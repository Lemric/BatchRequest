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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Lemric\BatchRequest\Bridge\Symfony\{SymfonyBatchRequestFacade, SymfonyTransactionExecutor};
use Symfony\Component\HttpKernel\HttpKernelInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SymfonyTransactionExecutor::class)
        ->args([service(HttpKernelInterface::class)])
        ->public()
        ->autoconfigure(false);

    $services->set(SymfonyBatchRequestFacade::class)
        ->args([
            '$httpKernel' => service(HttpKernelInterface::class),
            '$rateLimiterFactory' => null,
            '$logger' => service('logger')->nullOnInvalid(),
            '$maxBatchSize' => 50,
            '$maxConcurrency' => 8,
            '$maxTransactionContentLength' => 262144,
            '$forwardedHeadersWhitelist' => [],
            '$transactionExecutor' => null,
        ])
        ->public();
};

