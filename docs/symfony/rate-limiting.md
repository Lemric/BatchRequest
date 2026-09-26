# Symfony Rate Limiting

Rate limiting is **optional** and requires
[`symfony/rate-limiter`](https://symfony.com/doc/current/rate_limiter.html).

## Why it matters

Each item in a batch is an internal HTTP call. Without proportional
limiting, a single client request can trigger dozens of controller
executions. The facade consumes **one token per operation**
(`consume(max(1, $transactionCount))`).

## Configure a limiter

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        batch_request:
            policy: token_bucket
            limit: 100
            rate: { interval: '1 minute', amount: 100 }
```

Wire it into the bundle:

```yaml
# config/packages/lemric_batch_request.yaml
lemric_batch_request:
    rate_limiter: limiter.batch_request
```

Symfony registers factories as `limiter.<name>`.

## Behaviour

* Identifier: client IP (`$request->getClientIp()`), falling back to
  `unknown`.
* Rejection raises `RateLimitException`.
* Facade responds with **429** and
  `Content-Type: application/problem+json`:

```json
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

## Choosing limits

| Traffic profile | Suggestion |
|-----------------|------------|
| Public / anonymous | Low ceiling; prefer authentication |
| Authenticated API | Limit ≈ comfortable ops/minute × safety factor |
| Internal BFF | Higher ceiling, still proportional to batch size |

Remember: `max_batch_size: 50` with a limit of `100` allows only **two**
full batches per window per IP.
