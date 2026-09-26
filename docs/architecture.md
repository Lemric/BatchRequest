# Architecture

This document describes the internal design for contributors and
advanced integrators. Public HTTP behaviour is covered in
[Usage](usage.md) and [Responses](responses.md).

## Layering

```text
┌─────────────────────────────────────────────────────────────┐
│  Bridge (SymfonyBatchRequestFacade / LaravelBatchRequestFacade)
│    · extract context (headers, cookies, files, server)
│    · rate limit (Symfony, optional)
│    · map exceptions → HTTP problem documents
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│  Parser (JsonBatchRequestParser)
│    · size / JSON depth guards
│    · build Transaction value objects
│    · filter forwarded headers & server vars
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│  Handler (BatchRequestHandler) + Validator
│    · BatchRequestValidator / TransactionValidator
│    · ExecutionStrategy (FiberExecutionStrategy)
│    · TransactionExecutorInterface (framework kernel)
└──────────────────────────┬──────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────┐
│  ResponseFormatter
│    · classify Content-Type
│    · JSON decode / text / base64
│    · strip sensitive response headers
└─────────────────────────────────────────────────────────────┘
```

All core types under `Lemric\BatchRequest\` are framework-agnostic.
Bridges live in `Lemric\BatchRequest\Bridge\`.

## Value objects

| Class | Role |
|-------|------|
| `Model\BatchRequest` | Immutable batch (`transactions`, flags, metadata) |
| `Transaction` | One sub-request |
| `BatchResponse` | Immutable list of item arrays |

Interfaces: `BatchRequestInterface`, `TransactionInterface`,
`BatchResponseInterface`.

## Command / handler

```php
$command = new ProcessBatchRequestCommand($batchRequest);
$response = $handler->handle($command);
```

`BatchRequestHandler`:

1. Validates via `ValidatorInterface`.
2. Groups transactions with `ExecutionStrategyInterface`.
3. Executes via `TransactionExecutorInterface`.
4. Returns `BatchResponse`.

## Fiber execution strategy

`FiberExecutionStrategy` splits the list into groups:

* Contiguous `GET` / `HEAD` → one parallelisable group
* Any other method → singleton group (always serial)

`BatchRequestHandler` runs parallel groups with a pool of at most
`maxConcurrency` Fibers. Each fiber suspends once after start so the
scheduler can interleave cooperative I/O. **True overlap requires the
executor to suspend during waits** (e.g. async HTTP). Blocking PDO /
`usleep` will not speed up.

Writes stay serial to avoid corrupting shared services (Doctrine EM,
PDO, session) across concurrent fibers.

## Validation pipeline

```text
BatchRequestValidator
  ├── empty / max size
  ├── recursive batch (IS_INTERNAL)
  ├── per-tx content length
  └── TransactionValidator (method, URI, headers)
```

`CompositeValidator` can chain additional `ValidatorInterface`
implementations if you build a custom handler.

## Extension points

| Seam | Interface | Default |
|------|-----------|---------|
| Parse | `ParserInterface` | `JsonBatchRequestParser` |
| Validate | `ValidatorInterface` | `BatchRequestValidator` |
| Execute | `TransactionExecutorInterface` | Symfony / Laravel executor |
| Schedule | `ExecutionStrategyInterface` | `FiberExecutionStrategy` |
| Format | (concrete) | `ResponseFormatter` |

Inject custom collaborators when constructing the facade / handler, or
override Symfony DI services.

## Deprecated API

`Lemric\BatchRequest\BatchRequest` is a thin deprecated wrapper around
`SymfonyBatchRequestFacade`. It emits `E_USER_DEPRECATED` and will be
removed in **3.2**. Migrate controllers to the facade class.
