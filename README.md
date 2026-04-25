# Lemric Batch Request for Symfony and Laravel
<img src="./doc/logo.webp" width="250" height="250">

## Installing
Install from composer :
```
composer require lemric/batch-request
```
# Batch Requests

Send a single HTTP request that contains multiple API calls.
Independent operations are processed in parallel, while dependent operations are processed sequentially.
When all operations are complete, a consolidated response is returned to you and the HTTP connection is closed.

The order of the responses matches the order of the operations in the request.
You should process the responses accordingly to determine which operations were successful and which should be retried in a subsequent operation.

### Limitations
To limit the number of requests in a batch, use ```symfony/rate-limiter```.
Batch requests are limited by the symfony/rate-limiter configuration of requests per batch. Each call within a batch is counted separately for purposes of calculating API call limits.
### Batch Request
A batch request takes a JSON object consisting of an array of your requests. It returns an array of logical HTTP responses represented as JSON arrays.
Each response has a status code, an optional header array, and an optional body (which is a JSON-encoded string).

To make a batch request, send a POST request to an endpoint where the batch parameter is your JSON object.

```POST /batch```

#### Sample Batch Request

In this example, we will get information about two Pages that our application manages.

Formatted for readability.

```
curl -X POST --location 'http://localhost:8282/batch'
   --header 'Content-Type: application/json'
   --data '[
       {
          "method":"GET",
          "relative_url":"url"
        },  
        {
          "method":"GET",
          "relative_url":"url",
        }
   ], 
    "include_headers": true'
```

Once all operations are completed, a response is sent with the result of each operation.
Because the headers returned can sometimes be much larger than the actual API response, you may want to remove them for efficiency.
To include headers, remove the include_headers parameter or set it to false.

#### Sample Response

The body field contains a string encoded JSON object:

```
[
  {
    "code": 200,
    "body": "{
      \"name\": \"Page A Name\",
      \"id\": \"1\"
      }"
  },
  {
    "code": 200,
    "body": "{
      \"name\": \"Page B Name\",
      \"id\": \"1\"
      }"
  }
]
```

### Complex Batch Requests

It is possible to combine operations that would normally use different HTTP methods into a single batch request.
While GET and DELETE operations can only have a relative_url and a method field, POST and PUT operations can have an optional body field.
The body should be formatted as a raw HTTP POST string, similar to a URL query string.

#### Sample Request

The following example deletes an object and then creates the new object in a single operation:
```
curl -X POST /batch
   --header 'Content-Type: application/json'
   --data '[
       {
          "method":"DELETE",
          "relative_url":"url/{id}"
        },  
        {
          "method":"POST",
          "relative_url":"url",
          "body": {"id": "1", "message": "First post!"}
        }
   ], 
    "include_headers": false'
```

### Errors

Individual operations within a batch may fail (e.g. missing permissions,
validation errors, kernel exceptions). Failed sub-responses always carry
`Content-Type: application/problem+json` (RFC 7807) so HTTP clients can
dispatch on media type, while successful sub-responses keep
`application/json` (or whatever the underlying handler returned).

The error body keeps the legacy envelope `{"error": {"type": "...", "message": "..."}}`
for backward compatibility — it is a valid problem document and may be
extended by the application with additional RFC 7807 members
(`title`, `status`, `detail`, `instance`, `type`).

```
[
    {
      "code": 403,
      "headers": {
          "Content-Type": "application/problem+json",
          "WWW-Authenticate": "OAuth ..."
      },
      "body": {
          "error": {
              "type": "AccessDeniedHttpException",
              "message": "Insufficient scope"
          }
      }
    }
]
```

The same rule applies to top-level failures returned by the facade
(rate-limit exceeded, malformed batch envelope, internal errors): the
HTTP response is served with `Content-Type: application/problem+json`
and HTTP status 4xx/5xx. The body keeps the
`{"result": "error", "errors": [...]}` envelope for backward
compatibility:

```
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json

{
    "result": "error",
    "errors": [
        { "type": "rate_limit_error", "message": "Too many requests" }
    ]
}
```

Successful batches continue to return `Content-Type: application/json`.
Other requests in the batch complete independently and are returned as
normal with their own status code and content type — a single failed
sub-request never affects the success of the others.

#### JSON response bodies (`+json` suffix, RFC 6839)

Sub-responses whose `Content-Type` is `application/json`, `text/json`,
or any structured-syntax suffix `*/*+json` (e.g.
`application/problem+json`, `application/vnd.api+json`,
`application/ld+json`) are decoded into the `body` field as an array
instead of being returned as a raw JSON string. Charset and other media
type parameters are ignored during detection.

#### Mixed content types in a single batch

A batch may freely mix sub-requests that return different media types —
JSON, HTML, XML, SVG, PDF, PNG, `application/octet-stream`,
`BinaryFileResponse`, `StreamedResponse`, `204 No Content`, etc. The
formatter classifies each sub-response and shapes its `body` so the
outer batch envelope (which is itself JSON) is always safe to serialise:

| Response Content-Type                                                                 | `body` type | extra field             |
|---------------------------------------------------------------------------------------|-------------|-------------------------|
| `application/json`, `text/json`, `*/*+json`                                           | `array` (decoded) | —                       |
| Malformed JSON with a JSON content type                                               | `string` (raw) | —                       |
| `text/*` (html, plain, css, csv, …)                                                   | `string`    | —                       |
| `application/xml`, `application/*+xml`, `image/svg+xml`, `application/javascript`, `application/yaml`, `application/x-www-form-urlencoded`, `application/graphql`, `application/sql` | `string`    | —                       |
| Anything else (`image/png`, `application/pdf`, `application/octet-stream`, missing/unknown content type, …) | `string` (base64) | `body_encoding: "base64"` |
| Empty body / `204 No Content`                                                         | `""` (empty string) | —                       |

`BinaryFileResponse` and `StreamedResponse` are materialised before
formatting (their `getContent()` returns `false`, which would otherwise
silently drop the payload).

Example mixed batch response:

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

Clients should check for the optional `body_encoding` field; when it
equals `"base64"`, decode the `body` to recover the original bytes.

### Timeouts

Large or complex batches may timeout if it takes too long to complete all the requests in the batch. 
In such a case, the result is a partially completed batch. In a partially-completed batch, requests that complete successfully will return normal output with status code 200. 
Responses to requests that do not succeed will be null. You can retry any request that fails.

### Using Multiple Access Tokens

Individual requests in a single batch request can specify their own access tokens as query string or form post parameters. In this case, the top-level access token is considered a fallback token and will be used if an individual request does not explicitly specify an access token.

This can be useful if you want to query the API using multiple different user tokens, or if some of your calls need to be made using an application access token.

You must include an access token as a top-level parameter, even if each individual request contains its own token.

### Upload Binary Data

You can upload multiple binary items as part of a batch call. To do this, you must add all binary items to your request as multipart/mime attachments, and each operation must reference its binary items using the attached_files property in the operation. 
The attached_files property can take a comma-separated list of attachment names as its value.

The following example shows how to upload 2 photos in a single batch call:

```
curl 
     -F 'access_token=…' \
     -F 'batch=[{"method":"POST","relative_url":"me/photos","body":"message=My cat photo","attached_files":"file1"},{"method":"POST","relative_url":"me/photos","body":"message=My dog photo","attached_files":"file2"},]' \
     -F 'file1=@cat.gif' \
     -F 'file2=@dog.jpg' \
    /batch
```

## Example for Symfony
### Controller
```php
<?php

namespace App\Controller;

use Lemric\BatchRequest\BatchRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractController
{
    #[Route('/batch', name: 'batch')]
    public function indexAction(Request $request, BatchRequest $batchRequest): Response
    {
        return $batchRequest->handle($request);
    }
}
```

### services.yaml
```yaml
Lemric\BatchRequest\BatchRequest: ~
    bind: <-- Only if used syfmony/rate-limiter
        $rateLimiterFactory: '@limiter.authenticated_api' <-- Use proper configuration service name
```

### Symfony Profiler integration

The package ships a first-class Symfony Profiler integration so you can
debug every batch — and every individual sub-request — straight from the
WebProfiler toolbar. It is compatible with **Symfony 6.4 → 8.x**.

#### 1. Register the bundle

```php
// config/bundles.php
return [
    // ...
    Lemric\BatchRequest\Bridge\Symfony\BatchRequestBundle::class => ['all' => true],
];
```

The bundle auto-registers `SymfonyBatchRequestFacade` and
`SymfonyTransactionExecutor` as public services, so you can keep
injecting the facade in your controllers exactly as before.

#### 2. (Optional) Configuration

```yaml
# config/packages/lemric_batch_request.yaml
lemric_batch_request:
    max_batch_size: 50
    max_concurrency: 8
    max_transaction_content_length: 262144
    forwarded_headers_whitelist: ['x-trace-id', 'x-request-id']
    rate_limiter: limiter.authenticated_api   # service id, optional
    profiler: '%kernel.debug%'                # default: kernel.debug
```

All keys are optional. Defaults match the constructor of
`SymfonyBatchRequestFacade`. The `profiler` flag lets you force-enable
or force-disable the profiler integration regardless of `kernel.debug`.

#### 3. What you get in the profiler

When the profiler is enabled, the bundle decorates the transaction
executor with `Lemric\BatchRequest\Bridge\Symfony\Profiler\TraceableTransactionExecutor`
and registers a `BatchRequestDataCollector`. The result is a dedicated
**Batch Request** panel in the Symfony Profiler exposing, for the
current request:

- A toolbar item with the number of sub-requests and a red badge when
  any of them failed.
- Aggregated metrics: total transactions, failures, cumulative
  duration (ms), total response payload (KiB).
- A per-transaction table with method, URI, HTTP status, duration,
  memory delta and result.
- A collapsible *Inspect transaction* section for each row containing
  full request headers, request body (truncated to 16 KiB with an
  explicit marker), response headers, decoded response body and the
  exception/error envelope when applicable.

The traceable executor is tagged with `kernel.reset`, so the integration
is safe to use in long-running workers (FrankenPHP, Swoole, RoadRunner,
FPM with `kernel.reset` enabled) — the trace buffer is cleared between
requests.

> **Note:** rendering the panel requires `symfony/web-profiler-bundle`
> and `symfony/twig-bundle` (already part of every standard Symfony
> dev install). The runtime collector itself only depends on
> `symfony/http-kernel`.

#### 4. Disabling the integration in production

The profiler services are only wired when `profiler` is `true` (or by
default when `%kernel.debug%` is `true`), so production builds pay no
runtime cost. To disable it explicitly even in dev:

```yaml
# config/packages/dev/lemric_batch_request.yaml
lemric_batch_request:
    profiler: false
```

## Example for Laravel
### Controller
```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lemric\BatchRequest\Bridge\Laravel\LaravelBatchRequestFacade;

class BatchController extends Controller
{
    public function __construct(
        private LaravelBatchRequestFacade $batchRequest
    ) {}

    public function handle(Request $request): JsonResponse
    {
        return $this->batchRequest->handle($request);
    }
}
```

### config/app.php
```php
'providers' => [
    // ...
    Lemric\BatchRequest\Bridge\Laravel\LaravelServiceProvider::class,
],
```

### config/batch-request.php
```php
return [
    'max_batch_size' => env('BATCH_REQUEST_MAX_SIZE', 50),
];
```
