# Symfony Integration

The Symfony bridge provides:

* `BatchRequestBundle` — service wiring and optional profiler
* `SymfonyBatchRequestFacade` — entry point for controllers
* `SymfonyTransactionExecutor` — runs sub-requests via `HttpKernel`
* Profiler panel + `TraceableTransactionExecutor` (dev)

Compatible with **Symfony 6.4, 7.x and 8.x**.

## Contents

1. [Register the bundle](#register-the-bundle)
2. [Expose a route](controller.md)
3. [Configuration reference](configuration.md)
4. [Rate limiting](rate-limiting.md)
5. [Web Profiler](profiler.md)

## Register the bundle

```php
// config/bundles.php
return [
    // ...
    Lemric\BatchRequest\Bridge\Symfony\BatchRequestBundle::class => ['all' => true],
];
```

The bundle registers public services:

* `Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade`
* `Lemric\BatchRequest\Bridge\Symfony\SymfonyTransactionExecutor`

You can inject the facade directly into controllers.

## Minimal controller

```php
namespace App\Controller;

use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class BatchController
{
    #[Route('/batch', name: 'app_batch', methods: ['POST'])]
    public function __invoke(Request $request, SymfonyBatchRequestFacade $batch): Response
    {
        return $batch->handle($request);
    }
}
```

See [Controller & routing](controller.md) for firewall tips and legacy
API notes.

## Optional configuration

```yaml
# config/packages/lemric_batch_request.yaml
lemric_batch_request:
    max_batch_size: 50
    max_concurrency: 8
    max_transaction_content_length: 262144
    forwarded_headers_whitelist: ['x-trace-id', 'x-request-id']
    rate_limiter: null
    profiler: '%kernel.debug%'
```

Full reference: [Configuration](configuration.md).

## Next

* [Installation](../installation.md)
* [Usage protocol](../usage.md)
* [Security model](../security.md)
