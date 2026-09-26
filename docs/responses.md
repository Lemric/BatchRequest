# Responses

A successful parent request returns HTTP **200** with
`Content-Type: application/json` and a JSON **array**. Array length and
order match the input operations.

## Item shape

```json
{
  "code": 200,
  "body": {},
  "headers": {},
  "body_encoding": "base64"
}
```

| Field | Always present | Description |
|-------|----------------|-------------|
| `code` | yes | HTTP status of the sub-response. |
| `body` | yes | Decoded JSON object/array, UTF-8 string, empty string, or base64 string. |
| `headers` | only if `include_headers` | Lower/original case map of the **last** value per header name. Sensitive headers are stripped (see below). |
| `body_encoding` | only for binary | Present and equal to `"base64"` when `body` holds encoded bytes. |

## Content-type handling

The formatter classifies each sub-response so the outer JSON envelope
remains serialisable:

| Sub-response `Content-Type` | `body` type | Extra field |
|-----------------------------|-------------|-------------|
| `application/json`, `text/json`, `*/*+json` (RFC 6839) | decoded `array` / scalar JSON | — |
| Malformed JSON with a JSON content type | raw `string` | — |
| `text/*` | `string` | — |
| `application/xml`, `application/*+xml`, `image/svg+xml`, `application/javascript`, `application/yaml`, `application/x-www-form-urlencoded`, `application/graphql`, `application/sql` | `string` | — |
| Anything else (PNG, PDF, `octet-stream`, missing/unknown, …) | base64 `string` | `body_encoding: "base64"` |
| Empty body / `204 No Content` | `""` | — |

Charset and other media-type parameters are ignored during detection.

### Mixed batch example

```json
[
  {
    "code": 200,
    "headers": {"content-type": "application/json"},
    "body": {"id": 1, "name": "Page A"}
  },
  {
    "code": 200,
    "headers": {"content-type": "text/html; charset=utf-8"},
    "body": "<!doctype html><h1>Hello</h1>"
  },
  {
    "code": 200,
    "headers": {"content-type": "image/png"},
    "body": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=",
    "body_encoding": "base64"
  },
  {
    "code": 204,
    "headers": {},
    "body": ""
  }
]
```

Clients **must** check for `body_encoding === "base64"` before treating
`body` as text.

## Binary & streamed responses

* `BinaryFileResponse` — read from disk in ~8 KiB chunks and base64-encoded
  incrementally (peak RAM ≈ chunk size).
* `StreamedResponse` — content is captured via output buffering, then
  classified like a normal body.

## Stripped response headers

Even when `include_headers` is enabled, the following headers are never
copied into the envelope (session / credential / fingerprint leakage):

* `set-cookie`
* `authorization`
* `proxy-authenticate` / `proxy-authorization`
* `server`
* `x-powered-by`

## Success semantics

`BatchResponse::isSuccessful()` is `true` only when **every** item has a
`2xx` status. `getFailureCount()` counts items with `code >= 400`.

These helpers are available when you use the handler / model layer
directly; the HTTP facade returns the raw array from `toArray()`.
