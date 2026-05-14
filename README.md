# Siro Core Framework v0.24.0

**The Fastest PHP Micro-Framework** — Zero dependencies, sub-millisecond boot, OWASP Top 10 mitigated by default.

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen.svg)](https://php.net)
[![Tests](https://img.shields.io/badge/tests-1350%2B%20passing-brightgreen.svg)](tests/)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20max-brightgreen.svg)](https://phpstan.org)
[![Security](https://img.shields.io/badge/security-audited-brightgreen)](https://github.com/SiroSoft/siro-core)
[![Packagist](https://img.shields.io/packagist/v/sirosoft/core.svg)](https://packagist.org/packages/sirosoft/core)

---

## Performance

```
Static route dispatch:    0.002ms (488K ops/sec)
Dynamic route dispatch:   0.009ms
Middleware overhead:      ~0.001ms per layer
Cold boot:                ~1ms (Linux + OPcache)
1000 routes registered:   1.2ms
Memory per request:       ~2KB
```

## Quick Start

```php
use Siro\Core\App;
use Siro\Core\Route;

$app = new App(__DIR__);
Route::setRouter($app->router);
$app->boot();

Route::get('/hello/{name}', function ($req) {
    return ['message' => 'Hello ' . $req->param('name')];
});

$app->run();
```

## Documentation

| Module | File | Contents |
|--------|------|----------|
| **Database** | [docs/DATABASE.md](docs/DATABASE.md) | QueryBuilder, Models, Migrations, Relations, Transactions |
| **Cache** | [docs/CACHE.md](docs/CACHE.md) | File/Redis drivers, Query caching, TTL |
| **Logger** | [docs/LOGGER.md](docs/LOGGER.md) | Log levels, Sanitization, Audit trail, Trace replay |
| **Router** | [docs/ROUTER.md](docs/ROUTER.md) | Routing, Middleware, Route Attributes (PHP 8), CORS |
| **JWT Auth** | [docs/JWT.md](docs/JWT.md) | Access/Refresh tokens, Key rotation, JTI blacklist |
| **Validation** | [docs/VALIDATION.md](docs/VALIDATION.md) | Rules, Custom messages, FormRequest |
| **CLI** | [docs/CLI.md](docs/CLI.md) | All 70 commands reference |
| **Security** | [docs/SECURITY.md](docs/SECURITY.md) | CSP, CORS, CSRF, Rate Limiting, Best practices |

## Features

| Area | Capabilities |
|------|-------------|
| **Auth** | JWT with algorithm pinning, key rotation, per-token revocation, refresh rotation, API keys |
| **Database** | QueryBuilder, ORM (HasOne/HasMany/BelongsTo/BelongsToMany), Migrations, SQLite/MySQL/PostgreSQL |
| **Router** | Static O(1) routes, Dynamic {param}, Groups, Middleware pipeline, PHP 8 Attributes, Named routes |
| **Cache** | File and Redis drivers, auto-prefix, query builder integration |
| **Validation** | 15+ rules, custom rules, custom messages, FormRequest |
| **Security** | CSP, CORS, CSRF (session + double-submit), Rate limiting, Audit logging, Log sanitization |
| **CLI** | 70 commands: make CRUD/auth, migrate, cache, queue, benchmark, debug |
| **Middleware** | Auth, CORS, CSRF, CSP, ETag, Version, Metrics, Audit, Idempotency, Throttle, Security Headers |
| **Storage** | Local filesystem, S3-compatible (AWS Signature V4), path traversal protection |
| **Queue** | DB-based, exponential backoff, timeout, priority, failed job retry |
| **Encryption** | AES-256-CBC, HKDF key separation, Encrypt-then-MAC |
| **Event System** | Pub/sub, wildcards, one-time listeners |
| **Debug** | Trace headers, log replay, slow query detection, request profiling |

## Install

```bash
composer require sirosoft/core
```

Or create a full project:

```bash
composer create-project sirosoft/api my-api
```

## Requirements

- PHP 8.2+
- ext-pdo, ext-json, ext-mbstring
- ext-redis (optional), ext-openssl (optional)

## Test

```bash
composer test              # Unit tests
composer check             # PHPStan + PHPUnit
php siro benchmark         # Performance benchmark
php vendor/bin/phpunit --coverage-html coverage/
```

## Security

Report vulnerabilities to security@sirophp.com

## License

MIT © SiroSoft
