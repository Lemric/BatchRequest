# Symfony Controller & Routing

## Recommended controller

```php
namespace App\Controller;

use Lemric\BatchRequest\Bridge\Symfony\SymfonyBatchRequestFacade;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class BatchController
{
    public function __construct(
        private readonly SymfonyBatchRequestFacade $batch,
    ) {
    }

    #[Route('/batch', name: 'app_batch', methods: ['POST'])]
    #[IsGranted('ROLE_USER')] // protect like the rest of your API
    public function __invoke(Request $request): Response
    {
        return $this->batch->handle($request);
    }
}
```

## Routing YAML (alternative)

```yaml
# config/routes.yaml
app_batch:
    path: /batch
    controller: App\Controller\BatchController
    methods: [POST]
```

## Firewall

Treat `/batch` as a **privileged aggregator**. Use the same
authentication and authorization as the APIs it proxies. Anonymous
public batch endpoints amplify abuse (rate limiting alone is not enough).

Example `security.yaml` fragment:

```yaml
security:
    firewalls:
        api:
            pattern: ^/api|^/batch
            stateless: true
            # ... your authenticator
```

## CSRF

For cookie-based session APIs, CSRF protection on `POST /batch` is
recommended (Same as any state-changing endpoint). Stateless token APIs
typically rely on `Authorization` instead.

## Legacy class

```php
use Lemric\BatchRequest\BatchRequest; // @deprecated
```

`BatchRequest` still works but triggers `E_USER_DEPRECATED` and will be
removed in **3.2**. Replace type-hints and service bindings with
`SymfonyBatchRequestFacade`.

### Migrating service bindings

Before:

```yaml
Lemric\BatchRequest\BatchRequest:
    bind:
        $rateLimiterFactory: '@limiter.authenticated_api'
```

After: configure `lemric_batch_request.rate_limiter` or inject
`SymfonyBatchRequestFacade` as shown in
[Configuration](configuration.md).

## Request attributes used by the facade

From the parent `Request` the facade extracts:

| Context key | Source |
|-------------|--------|
| `include_headers` | query **or** request parameter (boolean) |
| `client_identifier` | `$request->getClientIp()` |
| `headers` | `$request->headers->all()` (filtered later) |
| `cookies` | `$request->cookies->all()` |
| `files` | `$request->files->all()` |
| `server` | `$request->server->all()` (whitelisted later) |

Body content is read via `$request->getContent()`.
