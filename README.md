# Siro Core Framework v0.26.0

**The Fastest PHP Micro-Framework** — Zero dependencies, sub-millisecond boot, OWASP Top 10 mitigated by default. Built for developers who demand raw speed, enterprise-grade security, and a ridiculously clean codebase.

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen.svg)](https://php.net)
[![Tests](https://img.shields.io/badge/tests-19037%20total-brightgreen.svg)](tests/)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen.svg)](https://phpstan.org)
[![Psalm](https://img.shields.io/badge/Psalm-Level%201-brightgreen.svg)](https://psalm.dev)
[![Security](https://img.shields.io/badge/security-OWASP%20Top%2010%20Mitigated-brightgreen)](docs/SECURITY.md)
[![Mutation](https://img.shields.io/badge/mutation-MSI%20≥80%25-brightgreen)](https://infection.github.io)
[![SBOM](https://img.shields.io/badge/sbom-CycloneDX-blue)](https://cyclonedx.org)
[![SLSA](https://img.shields.io/badge/slsa-1-brightgreen)](https://slsa.dev)
[![Fuzzing](https://img.shields.io/badge/fuzz-17851%20tests-brightgreen)](tests/fuzz/)
[![Chaos](https://img.shields.io/badge/chaos-engineering-blueviolet)](scripts/chaos-test.php)
[![Load Test](https://img.shields.io/badge/load%20test-k6%20|%20ab-blue)](scripts/loadtest.php)
[![Packagist](https://img.shields.io/packagist/v/sirosoft/core.svg)](https://packagist.org/packages/sirosoft/core)
[![Downloads](https://img.shields.io/packagist/dt/sirosoft/core.svg)](https://packagist.org/packages/sirosoft/core)

---

## Why Siro?

Siro is a **debugging-first, observability-first, security-first** PHP framework. It ships **zero** third-party dependencies, boots in under a millisecond, and achieves PHPStan Level Max + Psalm Level 1 across the entire codebase. Every design decision prioritizes developer experience, production safety, and auditability.

| Capability | Siro |
|-----------|:----:|
| Runtime Dependencies | **0** |
| Cold Boot | **~1ms** |
| Memory per Request | **~2KB** |
| Static Route Dispatch | **488K ops/sec** |
| Fuzz Testing | **17,851 tests** |
| Mutation Testing | **MSI ≥80%** |
| Chaos Engineering | **7 scenarios** |
| Request Replay | **Built-in** |
| SLSA Provenance | **Supported** |
| SBOM (CycloneDX) | **Auto-generated** |
| PHPStan | **Level Max — 0 errors** |
| Psalm | **Level 1 — 0 errors** |
| Prometheus Metrics | **Built-in** |

| Metric | Siro |
|--------|:----:|
| Static route dispatch | **0.002ms** (488K ops/sec) |
| Dynamic route dispatch | **0.009ms** |
| Middleware overhead | **~0.001ms** per layer |
| Cold boot | **~1ms** (Linux + OPcache) |
| 1000 routes registered | **1.2ms** |
| Memory per request | **~2KB** |
| Dependencies | **0** |
| PHPStan errors | **0** |

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

```bash
composer create-project sirosoft/api my-api
cd my-api
php siro serve
# 🚀 Ready at http://localhost:8080
```

## Feature Highlights

| Area | Capabilities |
|------|-------------|
| **Auth** | JWT with algorithm pinning, key rotation, per-token revocation, refresh rotation, API keys |
| **Database** | QueryBuilder, ORM (HasOne/HasMany/BelongsTo/BelongsToMany), Migrations, SQLite/MySQL/PostgreSQL |
| **Router** | Static O(1) routes, Dynamic {param}, Groups, Middleware pipeline, PHP 8 Attributes, Named routes |
| **Cache** | File and Redis drivers, auto-prefix, query builder integration |
| **Validation** | 15+ rules, custom rules, custom messages, FormRequest |
| **Security** | CSP, CORS, CSRF (session + double-submit), Rate limiting, Audit logging, Log sanitization |
| **CLI** | 70+ commands: make CRUD/auth, migrate, cache, queue, benchmark, debug |
| **Middleware** | Auth, CORS, CSRF, CSP, ETag, Version, Metrics, Audit, Idempotency, Throttle, Security Headers |
| **Storage** | Local filesystem, S3-compatible (AWS Signature V4), path traversal protection |
| **Queue** | DB-based, exponential backoff, timeout, priority, failed job retry |
| **Encryption** | AES-256-CBC, HKDF key separation, Encrypt-then-MAC |
| **Event System** | Pub/sub, wildcards, one-time listeners |
| **Debug** | Trace headers, log replay, slow query detection, request profiling |

## Documentation

| Module | File |
|--------|------|
| **Database** | [docs/DATABASE.md](docs/DATABASE.md) |
| **Cache** | [docs/CACHE.md](docs/CACHE.md) |
| **Logger** | [docs/LOGGER.md](docs/LOGGER.md) |
| **Router** | [docs/ROUTER.md](docs/ROUTER.md) |
| **JWT Auth** | [docs/JWT.md](docs/JWT.md) |
| **Validation** | [docs/VALIDATION.md](docs/VALIDATION.md) |
| **CLI** | [docs/CLI.md](docs/CLI.md) |
| **Security** | [docs/SECURITY.md](docs/SECURITY.md) |

## Requirements

- PHP 8.2+
- ext-pdo, ext-json, ext-mbstring
- ext-redis *(optional)*, ext-openssl *(optional)*

## Install

```bash
composer require sirosoft/core
```

Or bootstrap a full project with CRUD scaffolding, auth, and CLI:

```bash
composer create-project sirosoft/api my-api
```

## Test & Benchmark

```bash
composer test              # Run unit tests
composer check             # PHPStan static analysis + PHPUnit + SBOM
make health                # Health check (CLI)
make docs                  # Generate API documentation
composer docs:generate     # Generate API docs (Composer)
make production-check      # Full production readiness check
php siro benchmark         # Performance benchmark suite
php vendor/bin/phpunit --coverage-html coverage/
```

## Security

We take security seriously. Report vulnerabilities to **security@sirophp.com**.

## License

MIT © SiroSoft
