# Security Model

`lemric/batch-request` turns a single authenticated (or public) HTTP
request into many internal sub-requests. That is a powerful primitive
and a classic SSRF / privilege-amplification surface. This page
documents the built-in controls.

For vulnerability reporting, see [SECURITY.md](../SECURITY.md).

## Threat model (summary)

| Threat | Mitigation |
|--------|------------|
| SSRF to absolute URLs | `relative_url` must start with `/`; `://` and `//` rejected |
| Path traversal | Regex + iterative `rawurldecode` (up to 5 passes) |
| HTTP response splitting | Header names (RFC 7230 tchar) + CRLF/`NUL` banned in values |
| Header privilege escalation | Sensitive parent headers never forwarded |
| Recursive batch bombs | Sub-requests carry `IS_INTERNAL`; nested batches rejected |
| Payload DoS | 5 MiB envelope, 256 KiB body/tx, max 50 ops, `parse_str` field cap |
| Info disclosure | Client errors sanitised; secrets redacted in logs |

## URI rules

Accepted:

```text
/api/users/1
/api/search?q=test
```

Rejected:

```text
https://evil.example/api
//evil.example/api
/api/../etc/passwd
/%2e%2e/etc/passwd
/%252e%252e/etc/passwd
/api%0d%0aX-Injected:%20yes
```

Dangerous characters in the decoded URI (`<`, `>`, `"`, `'`) are also
rejected.

## Allowed HTTP methods

`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`.

Anything else fails validation.

## Header forwarding

Parent request headers are **not** copied wholesale into sub-requests.

Default allow-list:

* `accept`
* `accept-language`
* `content-type`
* `user-agent`

Always blocked (even if listed in a custom whitelist):

* `host`, `cookie`, `set-cookie`, `authorization`, `x-csrf-token`
* `x-forwarded-*`, `x-real-ip`, `forwarded`
* hop-by-hop: `connection`, `keep-alive`, `te`, `trailer`,
  `transfer-encoding`, `upgrade`, `proxy-*`

Customise the allow-list via Symfony
`forwarded_headers_whitelist` or the parser constructor. Sensitive names
are still dropped.

Per-operation `headers` and `authorization` fields are applied after
filtering and **may** set credentials intentionally.

## Server variable whitelist

Only these `$_SERVER` keys propagate to sub-requests:

`REMOTE_ADDR`, `REMOTE_PORT`, `SERVER_NAME`, `SERVER_PORT`,
`SERVER_PROTOCOL`, `REQUEST_METHOD`, `REQUEST_SCHEME`, `REQUEST_TIME`,
`REQUEST_TIME_FLOAT`, `HTTPS`.

Every sub-request additionally receives `IS_INTERNAL = true`.

## Recursive batches

If a sub-request targets the batch endpoint again, the nested call
arrives with `IS_INTERNAL` in its server bag. The batch validator
rejects such requests:

```text
Invalid or potentially unsafe URL: Recursive batch requests are not allowed
```

An explicit metadata flag `is_recursive_batch: true` is also rejected.

## Size limits

| Limit | Default | Config |
|-------|---------|--------|
| JSON envelope | 5 MiB | parser `$maxContentLength` |
| Per-transaction body | 256 KiB | `max_transaction_content_length` |
| Operations per batch | 50 | `max_batch_size` |
| `parse_str` fields | 1000 | hard-coded |
| JSON depth | 32 | hard-coded |

## Rate limiting

Use `symfony/rate-limiter` with the Symfony facade. Token consumption is
**proportional to batch size** (`consume(max(1, $count))`), so a batch of
50 costs 50 tokens — not 1. See
[Symfony rate limiting](symfony/rate-limiting.md).

## Recommendations for host applications

1. Protect `/batch` with the same authentication as your API.
2. Keep `max_batch_size` and concurrency aligned with your infrastructure.
3. Prefer short-lived tokens in per-operation `authorization` fields.
4. Do not put secrets in URIs that might appear in access logs.
5. Monitor `429` and validation failures.
