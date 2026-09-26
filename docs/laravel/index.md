# Laravel Integration

The Laravel bridge provides:

* `LaravelServiceProvider` — binds `LaravelBatchRequestFacade`
* `LaravelBatchRequestFacade` — entry point for controllers / routes
* `LaravelTransactionExecutor` — runs sub-requests via the HTTP kernel

Compatible with **Laravel 10, 11 and 12** (Illuminate HTTP ^10–^12).

## Contents

1. [Register the provider](#register-the-provider)
2. [Configuration](configuration.md)
3. [Controller & routing](controller.md)

## Register the provider

### Laravel 11+

```php
// bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    Lemric\BatchRequest\Bridge\Laravel\LaravelServiceProvider::class,
];
```

### Laravel 10

```php
// config/app.php
'providers' => [
    // ...
    Lemric\BatchRequest\Bridge\Laravel\LaravelServiceProvider::class,
],
```

Publish the config file:

```bash
php artisan vendor:publish --tag=batch-request-config
```

## Minimal route

```php
use Illuminate\Support\Facades\Route;
use Lemric\BatchRequest\Bridge\Laravel\LaravelBatchRequestFacade;

Route::post('/batch', function (
    Illuminate\Http\Request $request,
    LaravelBatchRequestFacade $batch,
) {
    return $batch->handle($request);
})->middleware('auth:sanctum');
```

See [Controller & routing](controller.md) for a dedicated controller
example.

## Next

* [Configuration](configuration.md)
* [Usage protocol](../usage.md)
* [Security model](../security.md)
