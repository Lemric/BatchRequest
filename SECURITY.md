# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 3.x     | :white_check_mark: |
| 2.x     | :x:                |
| 1.x     | :x:                |
| < 1.0   | :x:                |

Only the latest minor release of the currently supported major version
receives security fixes. Older major versions are not patched.

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

If you believe you have found a security vulnerability in
`lemric/batch-request`, report it privately using one of the following channels:

1. **GitHub Security Advisories** (preferred):
   [Report a vulnerability](https://github.com/Lemric/BatchRequest/security/advisories/new)
2. **Email**: [dominik@labudzinski.com](mailto:dominik@labudzinski.com)
   with the subject line `[SECURITY] lemric/batch-request`

Please include as much of the following information as possible:

* Type of issue (e.g. SSRF, path traversal, header injection, privilege
  escalation, information disclosure)
* Full paths of source file(s) related to the manifestation of the issue
* The location of the affected source code (tag / branch / commit or
  direct URL)
* Any special configuration required to reproduce the issue
* Step-by-step instructions to reproduce the issue
* Proof-of-concept or exploit code (if possible)
* Impact of the issue, including how an attacker might exploit it

This information helps us triage the report more quickly.

## Response Process

* You will receive an acknowledgement within **72 hours**.
* We will confirm the issue, determine affected versions and prepare a
  fix.
* A coordinated disclosure date will be agreed with you when possible.
* A security advisory will be published on GitHub once a fixed release
  is available (and optionally on [Packagist](https://packagist.org/packages/lemric/batch-request)
  via Composer advisory metadata).

Please give us a reasonable amount of time to publish a fix before any
public disclosure.

## Scope

In scope:

* Path traversal / SSRF via `relative_url`
* HTTP response splitting / header injection
* Recursive batch amplification
* Information disclosure through error messages or logs
* Unsafe forwarding of authentication or proxy headers
* Denial-of-service vectors specific to this library (e.g. payload /
  batch size bombs that bypass documented limits)

Out of scope (report to the application / framework instead):

* Misconfiguration of routes, firewalls or rate limiters in the host
  application
* Vulnerabilities in Symfony, Laravel or other third-party dependencies
  (report upstream)
* Issues that require an already-compromised application server

## Security Hardening Already Present

This library applies several defences by default. See
[docs/security.md](docs/security.md) for the full model. In short:

* Relative URIs only (no absolute / protocol-relative URLs)
* Path-traversal and CRLF detection with multi-decode defence
* Strict HTTP method allow-list
* Sensitive parent headers never forwarded to sub-requests
* Recursive batch requests rejected via `IS_INTERNAL`
* Payload and per-transaction body size limits
* Client-facing execution errors sanitised; secrets redacted from logs

## Preferential Treatment

We appreciate responsible disclosure. Credit will be given in the
advisory (unless you prefer to remain anonymous).
