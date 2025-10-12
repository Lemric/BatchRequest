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

namespace Lemric\BatchRequest\Bridge\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for batch request processing.
 */
class LaravelServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Configuration publishing would go here if needed
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(LaravelBatchRequestFacade::class, function ($app) {
            /* @var \Illuminate\Contracts\Container\Container $app */
            return new LaravelBatchRequestFacade(
                $app->make('Illuminate\Contracts\Http\Kernel'),
                $app->make('log'),
                50, // Default max batch size
            );
        });
    }
}
