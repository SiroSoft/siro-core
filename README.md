<div align="center">
  <h1>⚡ Siro Core</h1>
  <p><strong>Zero-dependency PHP engine for the Siro framework.</strong><br>
  Sub-millisecond boot · 19,034 tests · PHPStan level max · OWASP Top 10 mitigated</p>
</div>

<div align="center">

[![PHP 8.2+](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen.svg)](https://php.net)
[![Tests](https://img.shields.io/badge/tests-19.034%20pass-brightgreen)](tests/)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen)](https://phpstan.org)
[![Psalm](https://img.shields.io/badge/Psalm-Level%201-brightgreen)](https://psalm.dev)
[![Mutation](https://img.shields.io/badge/mutation-MSI%20≥80%25-brightgreen)](https://infection.github.io)
[![Security](https://img.shields.io/badge/security-OWASP%20Top%2010-brightgreen)](docs/SECURITY.md)
[![Fuzzing](https://img.shields.io/badge/fuzz-17.851%20tests-brightgreen)](tests/fuzz/)
[![Chaos](https://img.shields.io/badge/chaos-engineering-blueviolet)](scripts/chaos-test.php)
[![SBOM](https://img.shields.io/badge/sbom-CycloneDX-blue)](https://cyclonedx.org)
[![SLSA](https://img.shields.io/badge/slsa-1-brightgreen)](https://slsa.dev)
[![Packagist](https://img.shields.io/packagist/v/sirosoft/core)](https://packagist.org/packages/sirosoft/core)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

</div>

**Siro Core** is the engine behind the [Siro framework](https://github.com/SiroSoft/SiroPHP). It provides the DI container, router, ORM, auth, middleware pipeline, and CLI — all with **zero runtime dependencies**.

```bash
composer require sirosoft/core
```

Or bootstrap a full project:

```bash
composer create-project sirosoft/api my-api && cd my-api && php siro serve
```

---

## Features

| Layer | What's included |
|-------|----------------|
| **DI Container** | Autowiring, circular detection, contextual bindings, tags, rebound callbacks |
| **Router** | Static O(1) dispatch, dynamic params, groups, middleware pipeline, PHP 8 Attributes |
| **ORM** | Active Record, HasOne/HasMany/BelongsTo/BelongsToMany, eager loading, soft deletes, identity map, N+1 detection |
| **Query Builder** | SELECT, JOIN (INNER/LEFT/RIGHT/CROSS), WHERE, GROUP BY, HAVING, subqueries, pagination, aggregates, `whereHas`, row locking (FOR UPDATE/SHARE) |
| **Auth** | JWT with algorithm pinning, key rotation, per-token revocation, refresh rotation, API keys, RBAC |
| **Security** | CSP, CORS, CSRF (session + double-submit), rate limiting, audit logging, log sanitization |
| **Middleware** | Auth, CORS, CSP, CSRF, ETag, Version, Metrics, Audit, Idempotency, Throttle, Security Headers, JSON |
| **CLI** | 72 commands: make CRUD/auth, migrate, cache, queue, benchmark, debug, tinker |
| **Database** | Query Builder, Schema Builder (Blueprint), migrations, SQLite/MySQL/PostgreSQL |
| **Cache** | File + Redis drivers, auto-prefix, query/route/config caching |
| **Validation** | 15+ rules, custom rules + messages, FormRequest |
| **Queue** | DB-based, exponential backoff, timeout, priority, failed job retry |
| **Mail** | SMTP (STARTTLS), sendmail, async queuing, HTML + attachments |
| **Events** | Pub/sub, wildcards, one-time listeners, model lifecycle hooks |
| **Storage** | Local filesystem, S3-compatible (AWS Signature V4), MIME validation |
| **Encryption** | AES-256-CBC, HKDF key separation, Encrypt-then-MAC, `hash_equals` timing-safe |
| **Debug** | Request replay, trace search (by IP/path/error), `php siro why`, N+1 detection, slow query detection, log sanitization |

---

## Performance

```
Operation                         Avg          Ops/sec
─────────────────────────────────────────────────────────
  Container::make                 0.0009 ms    1.07M
  Response::success()             0.0005 ms    2.21M
  Route dispatch (static)         0.0042 ms    239K
  Route dispatch (1000 routes)    0.0023 ms    O(1)
  Middleware (5 layers)           0.0083 ms    120K
  Validation (5 rules)            0.0070 ms    142K
  Cold boot                       ~1 ms        —
  Memory per request              ~2 KB        —
  Average                         0.0035 ms    864K
```

---

## Quality

| Gate | Result |
|------|--------|
| **Unit tests** | 988 — **0 failures** |
| **Fuzz tests** | 17,851 — **0 failures** |
| **DAST tests** | 157 — **0 failures** |
| **Integration tests** | 42 — **0 failures** |
| **PHPStan** | Level Max — **0 errors** |
| **Psalm** | Level 1 (taint analysis) — **0 errors** |
| **Mutation testing** (Infection) | MSI ≥80% |
| **Chaos engineering** | 7/7 scenarios pass |
| **Composer audit** | 0 vulnerabilities |
| **SAST linter** | 0 errors |

---

## Quick start

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

---

## Requirements

- PHP 8.2+
- `ext-pdo`, `ext-json`, `ext-mbstring`
- `ext-redis` (optional, for cache/rate limiter)
- `ext-openssl` (optional, for Encrypter)

---

## Documentation

| Module | File |
|--------|------|
| Database | [docs/DATABASE.md](docs/DATABASE.md) |
| Cache | [docs/CACHE.md](docs/CACHE.md) |
| Logger | [docs/LOGGER.md](docs/LOGGER.md) |
| Router | [docs/ROUTER.md](docs/ROUTER.md) |
| JWT Auth | [docs/JWT.md](docs/JWT.md) |
| Validation | [docs/VALIDATION.md](docs/VALIDATION.md) |
| CLI | [docs/CLI.md](docs/CLI.md) |
| Security | [docs/SECURITY.md](docs/SECURITY.md) |

---

## Run tests

```bash
# All tests
php vendor/bin/phpunit --no-coverage

# By suite
php vendor/bin/phpunit tests/unit/          # 988 unit tests
php vendor/bin/phpunit tests/fuzz/          # 17,851 fuzz tests
php vendor/bin/phpunit tests/dast/          # 157 DAST tests
php vendor/bin/phpunit tests/integration/   # 42 integration tests

# Static analysis
php -d memory_limit=512M vendor/bin/phpstan analyse --level=max
php vendor/bin/psalm --taint-analysis

# Mutation testing
php -d zend_extension=xdebug vendor/bin/infection --min-msi=80 --threads=4

# Security
composer audit
php scripts/sast-linter.php
```

---

## CI/CD

8 GitHub Actions workflows: CI (quality + test + fuzz + chaos + SAST + DAST + benchmark), Test (lint + PHPStan + PHPUnit + mutation), CodeQL, Gitleaks, SLSA, Dependency Review, Release, Deploy.

---

## Security

Report vulnerabilities to **security@sirophp.com**.

---

<div align="center">
  <p>
    <a href="https://sirophp.com">Website</a> ·
    <a href="https://github.com/SiroSoft/SiroPHP">Skeleton</a> ·
    <a href="https://packagist.org/packages/sirosoft/core">Packagist</a>
  </p>
  <p>MIT © <a href="https://sirophp.com">SiroSoft</a></p>
</div>
