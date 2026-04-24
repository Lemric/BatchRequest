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

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for batch request processing.
 */
final class LaravelServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../../../config/batch-request.php';

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (!$this->app instanceof Application) {
            return;
        }

        $this->mergeConfigFrom(self::CONFIG_PATH, 'batch-request');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('batch-request.php'),
            ], 'batch-request-config');
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(LaravelBatchRequestFacade::class, function ($app) {
            /* @var \Illuminate\Contracts\Container\Container $app */
            $maxBatchSize = 50;
            if (method_exists($app, 'bound') && $app->bound('config')) {
                $maxBatchSize = (int) $app->make('config')->get('batch-request.max_batch_size', 50);
            }

            return new LaravelBatchRequestFacade(
                $app->make('Illuminate\Contracts\Http\Kernel'),
                $app->make('log'),
                $maxBatchSize,
            );
        });
    }
}
