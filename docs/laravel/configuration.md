# Laravel Configuration

## Published config

```bash
php artisan vendor:publish --tag=batch-request-config
```

Creates `config/batch-request.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Batch Size
    |--------------------------------------------------------------------------
    |
    | Maximum number of operations accepted in a single batch envelope.
    |
    */
    'max_batch_size' => env('BATCH_REQUEST_MAX_SIZE', 50),
];
```

| Key | Env | Default | Description |
|-----|-----|---------|-------------|
| `max_batch_size` | `BATCH_REQUEST_MAX_SIZE` | `50` | Max operations per request. |

## Service binding

`LaravelServiceProvider` registers a singleton
`LaravelBatchRequestFacade` constructed with:

* `Illuminate\Contracts\Http\Kernel`
* `log` channel (PSR-3)
* `config('batch-request.max_batch_size')`

Additional constructor options (`maxConcurrency`,
`maxTransactionContentLength`, `forwardedHeadersWhitelist`) use library
defaults unless you rebind the facade in your own service provider:

```php
use Lemric\BatchRequest\Bridge\Laravel\LaravelBatchRequestFacade;
use Illuminate\Contracts\Http\Kernel;

$this->app->singleton(LaravelBatchRequestFacade::class, function ($app) {
    return new LaravelBatchRequestFacade(
        kernel: $app->make(Kernel::class),
        logger: $app->make('log'),
        maxBatchSize: (int) config('batch-request.max_batch_size', 50),
        maxConcurrency: 4,
        maxTransactionContentLength: 131072,
        forwardedHeadersWhitelist: ['x-trace-id', 'x-request-id'],
    );
});
```

## Rate limiting

Laravel does not wire `symfony/rate-limiter` automatically. Use Laravel’s
own rate limiter middleware on the `/batch` route, and size limits so one
request cannot fan out unboundedly:

```php
Route::post('/batch', BatchController::class)
    ->middleware(['auth:sanctum', 'throttle:api']);
```

Prefer a dedicated limiter that accounts for batch size if you accept
large envelopes.
