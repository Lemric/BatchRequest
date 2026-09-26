# Lemric Batch Request Documentation

Batch multiple HTTP API calls into a single request. Compatible with
Symfony and Laravel.

## Table of Contents

### Getting started

1. [Installation](installation.md)
2. [Usage](usage.md) — request envelope, fields, tokens, file uploads
3. [Responses](responses.md) — status codes, headers, content types, binary
4. [Errors](errors.md) — sub-request and top-level failure formats

### Framework integration

5. [Symfony](symfony/index.md)
   * [Configuration reference](symfony/configuration.md)
   * [Controller & routing](symfony/controller.md)
   * [Rate limiting](symfony/rate-limiting.md)
   * [Web Profiler](symfony/profiler.md)
6. [Laravel](laravel/index.md)
   * [Configuration](laravel/configuration.md)
   * [Controller & routing](laravel/controller.md)

### Internals & security

7. [Security model](security.md)
8. [Architecture](architecture.md)

## What this library does

A client sends **one** HTTP `POST` whose body is a JSON array of
operations. Each operation describes a relative sub-request
(`method` + `relative_url` + optional `body` / `headers` / …).

The library:

1. Parses and validates the envelope.
2. Executes each operation against the host framework kernel
   (`HttpKernel` / Laravel `Kernel`) as an **internal sub-request**.
3. Aggregates every result into a JSON array whose order matches the
   input.

Independent `GET` / `HEAD` blocks may run concurrently via PHP Fibers;
mutating methods (`POST`, `PUT`, `PATCH`, `DELETE`, …) always run
sequentially to preserve causal ordering and shared container state
(Entity Manager, PDO, session).

## Minimal example

```http
POST /batch HTTP/1.1
Content-Type: application/json

[
  {"method": "GET", "relative_url": "/api/users/1"},
  {"method": "DELETE", "relative_url": "/api/posts/9"}
]
```

```http
HTTP/1.1 200 OK
Content-Type: application/json

[
  {"code": 200, "body": {"id": 1, "name": "Alice"}},
  {"code": 204, "body": ""}
]
```

## Version compatibility

| Package version | PHP   | Symfony     | Laravel        |
|-----------------|-------|-------------|----------------|
| 2.x             | ≥ 8.2 | 6.4 / 7 / 8 | 10 / 11 / 12   |

## Resources

* [Source code](https://github.com/Lemric/BatchRequest)
* [Issue tracker](https://github.com/Lemric/BatchRequest/issues)
* [Security policy](../SECURITY.md)
* [Packagist](https://packagist.org/packages/lemric/batch-request)
