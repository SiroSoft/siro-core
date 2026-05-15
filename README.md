# Siro Core Framework v0.26.1

**The debugging-first PHP framework.** Zero dependencies, sub-millisecond boot, OWASP Top 10 mitigated by default. Built for developers who want to fix production bugs in seconds, not hours.

```bash
# Production API fails → php siro why
# 5 seconds later you see: route, SQL, middleware, exception, possible cause, suggested fix
$ php siro why

  Last Request Summary
  ────────────────────────────────────────────────────────
  Route:    POST /api/orders
  Status:   ✗ 500 (842ms)
  ────────────────────────────────────────────────────────
  Timeline
    ✓ AuthMiddleware           [2ms]
    ✗ PaymentMiddleware        [800ms]  ← failure here
    ▸ INSERT INTO orders (…)   [812ms]  ⚠ SLOW
  Exception
    PDOException: Deadlock found when trying to get lock
  Suggested Fix
    ▸ Wrap transaction in retry loop
    ▸ php siro replay siro_a1b2c3d4 --edit
  ────────────────────────────────────────────────────────
```

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

## The Siro Flow — Integrated Terminal-Native Workflow

**For local development and small-team API workflows, you rarely need to leave the terminal.**

```bash
# ── BUILD ──────────────────────────────────────────────
composer create-project sirosoft/api my-api
cd my-api
php siro key:generate
php siro make:crud Product       # Controller + Service + Repository + Model + Migration + Test
php siro make:auth               # Auth: register/login/refresh/forgot/reset
php siro migrate
php siro serve                   # Start at :8080

# ── TEST ───────────────────────────────────────────────
php siro t POST /api/auth/login --body='{"email":"test@test.com","password":"123456"}'
php siro t GET /api/products
php siro t POST /api/orders --body='{"product_id":1,"quantity":5}'

# ── DEBUG ──────────────────────────────────────────────
php siro why                      # Why did production fail? (5 seconds)
php siro replay siro_a1b2c3       # Replay exact failed request
php siro replay siro_a1b2c3 --edit # Edit body → test fix
php siro tinker                   # Interactive PHP playground

# ── MONITOR ────────────────────────────────────────────
php siro log:tail                 # Local log streaming
php siro log:stats                # Request stats
php siro doctor                   # System health check
curl localhost:8080/health        # HTTP health check
curl localhost:8080/metrics       # Prometheus endpoint

# ── DOCUMENT ───────────────────────────────────────────
php siro make:openapi --with-swagger
php siro route:list
```

**Everything above is built-in — zero packages to install for these workflows.**  
(For production-scale monitoring, Siro integrates with standard tools: Prometheus, Grafana, Datadog.)

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
| **Debug** | Trace headers, log replay, slow query detection, request profiling, `siro tinker` REPL |
| **Observers** | Model lifecycle hooks: saving, creating, updating, deleting, force deleting |
| **Gzip** | Automatic compression for file downloads (text, JSON, XML, SVG, fonts) |

## Quality Assurance

Siro maintains **0 errors** across all quality gates — verified on every commit.

```bash
# Run all tests (19,038 tests, 31,652 assertions)
php vendor/bin/phpunit --no-coverage

# By suite:
php vendor/bin/phpunit tests/unit/          # 988 unit tests
php vendor/bin/phpunit tests/fuzz/          # 17,851 fuzz tests
php vendor/bin/phpunit tests/dast/          # 157 DAST security tests
php vendor/bin/phpunit tests/integration/   # 42 integration tests

# Static analysis
php vendor/bin/phpstan analyse --level=max    # Level Max — 0 errors
php vendor/bin/psalm --taint-analysis         # Level 1 — 0 errors

# Mutation testing (≥80% MSI)
php vendor/bin/infection --min-msi=80 --threads=4

# Chaos engineering
php scripts/chaos-test.php

# Security
composer audit                               # 0 dependency vulnerabilities
php scripts/sast-linter.php                  # SAST scan
php scripts/health-check.php                 # System health
```

| Quality Gate | Result | How to Verify |
|-------------|--------|---------------|
| **Unit Tests** | 988 tests, 2,547 assertions, **0 failures** | `phpunit tests/unit/` |
| **Fuzz Tests** | 17,851 tests, 28,849 assertions, **0 failures** | `phpunit tests/fuzz/` |
| **DAST Tests** | 157 tests, 166 assertions, **0 failures** | `phpunit tests/dast/` |
| **Integration Tests** | 42 tests, 90 assertions, **0 failures** | `phpunit tests/integration/` |
| **PHPStan** | Level Max — **0 errors** | `phpstan analyse --level=max` |
| **Psalm** | Level 1 — **0 errors** | `psalm --taint-analysis` |
| **Mutation Testing** | MSI ≥80% | `infection --min-msi=80` |
| **composer audit** | 0 vulnerabilities | `composer audit` |
| **SAST Linter** | 0 errors | `php scripts/sast-linter.php` |
| **Chaos Engineering** | 7/7 pass | `php scripts/chaos-test.php` |
| **Load Testing** | k6 + Apache Bench | `php scripts/loadtest.php` |
| **SBOM** | CycloneDX | `php scripts/generate-sbom.php` |
| **Total** | **19,038 tests, 31,652 assertions — 0 failures** | `phpunit --no-coverage` |

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
