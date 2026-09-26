# Errors

There are two layers of failure:

1. **Sub-request errors** — one operation failed; the parent HTTP status
   stays `200` and the array still contains one slot per operation.
2. **Top-level errors** — the batch envelope itself was rejected (parse,
   validation, rate limit, uncaught exception). The parent response uses
   `4xx` / `5xx` and `Content-Type: application/problem+json`.

## Sub-request errors

Failed sub-responses set `Content-Type: application/problem+json`
(RFC 7807) on the item’s headers when headers are included. The body
keeps the legacy envelope for backward compatibility:

```json
{
  "code": 403,
  "headers": {
    "Content-Type": "application/problem+json",
    "WWW-Authenticate": "Bearer"
  },
  "body": {
    "error": {
      "type": "AccessDeniedHttpException",
      "message": "Insufficient scope"
    }
  }
}
```

| Source | `error.type` | `error.message` |
|--------|--------------|-----------------|
| Symfony `HttpExceptionInterface` | short class name | exception message (safe) |
| Other executor failures | `ExecutionException` | `Internal server error` |
| Handler catch-all | `ExecutionException` | `Internal server error` |

Internal exception details are **not** exposed to API clients.

A single failed item never cancels siblings.

## Top-level errors

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json

{
  "result": "error",
  "errors": [
    {
      "type": "rate_limit_error",
      "message": "Too many requests"
    }
  ]
}
```

| Situation | HTTP status | `errors[].type` |
|-----------|-------------|-----------------|
| Rate limit exceeded (Symfony + rate limiter) | `429` | `rate_limit_error` |
| Validation failure (Laravel facade) | `400` | `validation_error` |
| Uncaught / parse / system error | `500` | `system_error` |

> **Symfony facade note:** parse and validation failures currently surface
> as a generic `system_error` (`500`) to avoid leaking internals. Prefer
> validating client input at the edge if you need distinct `400` codes.
> The Laravel facade maps `ValidationException` to `400`.

### Validation failure inside the handler

When the batch validator rejects the request *after* parsing, the
handler may return HTTP 200 with one synthetic `500` item per expected
transaction (historical behaviour). Prefer keeping batches within
configured limits so this path is never hit in production.

## Exception hierarchy (library)

```
BatchRequestException
├── ParseException          invalid / oversized JSON envelope
├── ValidationException     OWASP / size / recursive batch
├── RateLimitException      Symfony rate limiter rejected the call
└── ExecutionException      programmatic transaction failure helper
```

These are thrown by parsers / validators / limiters. Framework facades
catch them and convert them into HTTP problem documents as described
above.

## Logging

Facades and the handler redact common secret patterns
(`authorization`, `password`, `token`, `secret`, `api_key`, …) from
logged exception messages and stack traces. Traces are truncated to
4 KiB.
