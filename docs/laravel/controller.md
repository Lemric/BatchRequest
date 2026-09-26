# Laravel Controller & Routing

## Controller

```php
namespace App\Http\Controllers;

use Illuminate\Http\{JsonResponse, Request};
use Lemric\BatchRequest\Bridge\Laravel\LaravelBatchRequestFacade;

final class BatchController
{
    public function __construct(
        private readonly LaravelBatchRequestFacade $batch,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        return $this->batch->handle($request);
    }
}
```

## Routes

```php
// routes/api.php
use App\Http\Controllers\BatchController;
use Illuminate\Support\Facades\Route;

Route::post('/batch', BatchController::class)
    ->middleware(['auth:sanctum', 'throttle:api']);
```

## Authentication

Protect `/batch` like any other privileged API route. Sub-requests
inherit **only** the filtered context (see [Security](../security.md));
pass per-operation `authorization` when calling on behalf of different
users.

## Validation errors

Unlike the Symfony facade, `LaravelBatchRequestFacade` maps
`ValidationException` to HTTP **400**:

```json
{
  "result": "error",
  "errors": [
    {
      "type": "validation_error",
      "message": "Batch size 80 exceeds limit of 50"
    }
  ]
}
```

Parse / system failures still return **500** / `system_error`.

## Context extraction

| Context key | Source |
|-------------|--------|
| `include_headers` | `$request->input('include_headers', false)` |
| `client_identifier` | `$request->ip()` |
| `headers` | `$request->headers->all()` |
| `cookies` | `$request->cookies->all()` |
| `files` | `$request->allFiles()` |
| `server` | `$request->server->all()` |
