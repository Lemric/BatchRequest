# Usage

This page describes the **batch request protocol**: the JSON envelope
accepted by the facade and how individual operations are interpreted.

## Endpoint

Expose a single route (conventionally `POST /batch`) that delegates to
the framework facade. The body **must** be a JSON **array** of
operations. The root element cannot be an object.

```http
POST /batch HTTP/1.1
Host: example.com
Content-Type: application/json

[
  {
    "method": "GET",
    "relative_url": "/api/users/1"
  }
]
```

Maximum payload size defaults to **5 MiB**. Per-transaction body size
defaults to **256 KiB**. Batch size defaults to **50** operations.
These limits are configurable (see framework docs).

## Operation fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `method` | string | no (default `GET`) | HTTP method. Allowed: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`. |
| `relative_url` | string | yes\* | Path beginning with `/`. May include a query string (`/api/items?page=1`). Absolute and protocol-relative URLs are rejected. |
| `body` | object \| string | no | Request body. Objects are JSON-encoded; form-encoded strings are parsed into parameters. |
| `headers` | object | no | Per-operation headers. Override context headers. Values may be a string or an array of strings. |
| `authorization` | string | no | Sets `Authorization` for this operation only (overrides any existing Authorization header). |
| `attached_files` | string | no | Comma-separated multipart attachment names to bind to this operation. |

\* If omitted, the URI defaults to `/`.

Non-array items in the root JSON array are **silently skipped**.

## Query string on `relative_url`

```json
{
  "method": "GET",
  "relative_url": "/api/search?q=batch&limit=10"
}
```

Query parameters are merged into the transaction parameter bag. When
both a query string and a `body` object are present, **body parameters
win** on key collision.

## Include headers in the response

By default sub-response headers are omitted from the consolidated
envelope (they can dwarf the payload). Opt in with a query or form
parameter on the **parent** request:

```http
POST /batch?include_headers=1 HTTP/1.1
```

Or (Laravel / Symfony request bag):

```http
POST /batch
Content-Type: application/x-www-form-urlencoded

include_headers=1
```

When enabled, each item may contain a `headers` map.

## Mixed HTTP methods

A single batch may mix methods freely. Read-only blocks (`GET` /
`HEAD`) that appear consecutively may execute in parallel; any write
operation forces serial execution around it.

```json
[
  {"method": "GET", "relative_url": "/api/users/1"},
  {"method": "GET", "relative_url": "/api/users/2"},
  {"method": "DELETE", "relative_url": "/api/posts/9"},
  {"method": "POST", "relative_url": "/api/posts", "body": {"title": "Hi"}}
]
```

Execution order of **results** always matches input order, even when
reads ran concurrently.

## Per-operation authorization

```json
[
  {
    "method": "GET",
    "relative_url": "/api/me",
    "authorization": "Bearer user-token-a"
  },
  {
    "method": "GET",
    "relative_url": "/api/me",
    "authorization": "Bearer user-token-b"
  }
]
```

Parent `Authorization` / `Cookie` headers are **never** forwarded
automatically (see [Security](security.md)). Pass credentials explicitly
per operation when needed.

## Uploading binary data

Send the parent request as `multipart/form-data`. Put the JSON batch in
a field (commonly `batch`) and attach files. Reference attachments from
operations via `attached_files`:

```bash
curl -X POST https://example.com/batch \
  -F 'batch=[
    {"method":"POST","relative_url":"/api/photos","body":"message=Cat","attached_files":"file1"},
    {"method":"POST","relative_url":"/api/photos","body":"message=Dog","attached_files":"file2"}
  ]' \
  -F 'file1=@cat.gif' \
  -F 'file2=@dog.jpg'
```

> **Note:** Your controller must pass uploaded files into the parser
> context. The Symfony and Laravel facades do this automatically via
> `$request->files` / `$request->allFiles()`.

When using multipart, ensure the JSON array is still valid after form
decoding (no trailing commas, proper escaping).

## Partial failures

A failed sub-request **does not** abort the rest of the batch. Each
operation returns its own `code` / `body`. Clients should inspect every
item and retry only the failures.

## Timeouts

Very large batches may hit the PHP / web-server / proxy timeout. Prefer
smaller batches and rely on rate limiting. There is no built-in
“partial null slot” protocol — unfinished work surfaces as a transport
error on the parent connection.

## Next

* [Responses](responses.md)
* [Errors](errors.md)
* [Security](security.md)
