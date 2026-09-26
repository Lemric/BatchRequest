# Symfony Configuration Reference

Bundle root key: `lemric_batch_request`.

All options are optional. Defaults match
`SymfonyBatchRequestFacade::__construct()`.

## Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `max_batch_size` | int (≥ 1) | `50` | Maximum operations per envelope. |
| `max_concurrency` | int (≥ 1) | `8` | Fiber pool size for contiguous `GET`/`HEAD` groups. Set to `1` to force fully serial execution. |
| `max_transaction_content_length` | int (≥ 1) | `262144` | Max bytes for a single operation body (256 KiB). |
| `forwarded_headers_whitelist` | string[] | `[]` | Lower-case parent header names allowed to flow into sub-requests. Empty → built-in default allow-list (`accept`, `accept-language`, `content-type`, `user-agent`). Sensitive headers are never forwarded. |
| `rate_limiter` | string\|null | `null` | Service id of a `RateLimiterFactory`. See [Rate limiting](rate-limiting.md). |
| `profiler` | bool\|null | `null` | `null` → follow `%kernel.debug%`. `true` / `false` force on/off. |

## Example

```yaml
# config/packages/lemric_batch_request.yaml
lemric_batch_request:
    max_batch_size: 25
    max_concurrency: 4
    max_transaction_content_length: 131072
    forwarded_headers_whitelist:
        - x-trace-id
        - x-request-id
        - accept-language
    rate_limiter: limiter.batch_request
    profiler: '%kernel.debug%'
```

## Environment-specific overrides

```yaml
# config/packages/prod/lemric_batch_request.yaml
lemric_batch_request:
    profiler: false
    max_concurrency: 4
```

```yaml
# config/packages/dev/lemric_batch_request.yaml
lemric_batch_request:
    profiler: true
```

## Manual service definition

If you declare the facade yourself, the bundle’s
`TraceableExecutorWiringPass` still injects the traceable executor when
the profiler is enabled — unless you set `$transactionExecutor`
explicitly.

```php
// config/services.php
use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(SymfonyBatchRequestFacade::class)
        ->args([
            '$httpKernel' => service('http_kernel'),
            '$rateLimiterFactory' => service('limiter.batch_request'),
            '$logger' => service('logger'),
            '$maxBatchSize' => '%env(int:BATCH_REQUEST_MAX_SIZE)%',
        ]);
};
```

## Parameters published by the extension

| Parameter | Source |
|-----------|--------|
| `lemric_batch_request.max_batch_size` | config |
| `lemric_batch_request.max_concurrency` | config |
| `lemric_batch_request.max_transaction_content_length` | config |
| `lemric_batch_request.forwarded_headers_whitelist` | config |
