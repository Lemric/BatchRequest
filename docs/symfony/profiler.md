# Symfony Web Profiler

When `profiler` is enabled (default: `%kernel.debug%`), the bundle
decorates the transaction executor with
`TraceableTransactionExecutor` and registers
`BatchRequestDataCollector`.

Requires `symfony/web-profiler-bundle` and `symfony/twig-bundle` (standard
Symfony Flex `dev` stack).

## What you see

**Toolbar**

* Number of sub-requests in the current batch
* Red badge when any sub-request failed

**Panel — Batch Request**

* Aggregates: total transactions, failures, cumulative duration (ms),
  total response payload (KiB)
* Per-transaction table: method, URI, HTTP status, duration, memory
  delta, result
* Collapsible *Inspect transaction* section:
  * request headers
  * request body (truncated to 16 KiB with an explicit marker)
  * response headers
  * decoded response body
  * exception / error envelope when applicable

## Enable / disable

```yaml
# force on
lemric_batch_request:
    profiler: true

# force off (even in dev)
lemric_batch_request:
    profiler: false
```

Production builds with `profiler: false` (or `kernel.debug: false`) pay
**no** runtime tracing cost — profiler services are not wired.

## Long-running workers

The traceable executor is tagged with `kernel.reset`. Trace buffers are
cleared between requests, so the integration is safe with FrankenPHP,
Swoole, RoadRunner and FPM setups that honour `kernel.reset`.

## Opting out per service

If you set `$transactionExecutor` explicitly on a custom
`SymfonyBatchRequestFacade` definition to a non-null reference, the
compiler pass will not replace it with the traceable decorator.
