# Installation

## Requirements

* PHP **8.2** or higher
* Composer 2

Framework bridges are **optional**. The core package has **no** runtime
dependencies beyond PHP itself. Install Symfony or Laravel packages
according to the bridge you intend to use.

## Install the package

```bash
composer require lemric/batch-request
```

This installs the core library, parsers, validators and both framework
bridges (code is always present; runtime depends only on the classes you
instantiate).

## Optional dependencies

| Package | Purpose |
|---------|---------|
| `symfony/http-kernel` | Symfony facade / transaction executor |
| `symfony/dependency-injection` + `symfony/config` | `BatchRequestBundle` |
| `symfony/rate-limiter` | Per-client rate limiting (Symfony) |
| `symfony/web-profiler-bundle` + `symfony/twig-bundle` | Profiler panel (dev) |
| `illuminate/http` + `illuminate/support` | Laravel facade / service provider |

Example for a Symfony Flex application that already has the HTTP kernel:

```bash
composer require lemric/batch-request symfony/rate-limiter
```

Example for Laravel:

```bash
composer require lemric/batch-request
php artisan vendor:publish --tag=batch-request-config
```

## Verify the installation

```bash
composer show lemric/batch-request
```

Run the package test suite from a clone of the repository:

```bash
composer install
composer test
composer analyse
```

## Next steps

* [Usage](usage.md) — the batch request protocol
* [Symfony integration](symfony/index.md)
* [Laravel integration](laravel/index.md)
