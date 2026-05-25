<div align="center">
  <h1>⚡ Siro Core</h1>
  <p><strong>Core engine powering the Siro API Framework.</strong><br>
  Routing · ORM · CLI · Debug. Zero external dependencies.</p>
</div>

<div align="center">

[![PHP 8.2+](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen.svg)](https://php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen)](https://phpstan.org)
[![Tests](https://img.shields.io/badge/tests-19.496%20pass-brightgreen)](tests/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

</div>

---

## Install

```bash
# Standalone engine
composer require sirosoft/core

# Or with full skeleton (recommended)
composer create-project sirosoft/api my-app
```

---

## Debug production in 1 command

```bash
php siro api:why POST /api/orders
```

```
  Request
  ────────────────────────────────────────────────────────
  Route:    POST /api/orders
  Status:   ✗ 500
  Duration: 143ms

  Middleware Pipeline
    └ ✗ OrderMiddleware       35ms ⚠ slow

  SQL Queries
    └ ⚠ UPDATE inventory      102ms ⚠ slow

  Exception
    PDOException: Deadlock found when trying to get lock

  Possible Cause
    • Concurrent transaction conflict
    • Missing retry logic for deadlock scenarios

  Suggested Fix
    ▸ Wrap transaction in retry loop (max 3 attempts)

  Replay
    [r] php siro replay id --force
    [e] php siro replay id --edit
    [d] php siro replay id --diff
    [t] php siro make:test --from-trace=id
```

One command. Full context. No other framework has this flow.

---

## Full workflow

```
                        ┌─────────────────┐
                        │   HTTP Request   │
                        └────────┬────────┘
                                 ▼
                        ┌─────────────────┐
                        │  Router (O(1))   │
                        └────────┬────────┘
                                 ▼
                        ┌─────────────────┐
                        │   Middleware     │
                        └────────┬────────┘
                                 ▼
                        ┌─────────────────┐
                        │  Controller      │
                        │  → Service       │
                        │  → Model / DB    │
                        └────────┬────────┘
                                 ▼
                        ┌─────────────────┐
                        │  Resource / JSON │
                        └─────────────────┘
```

```bash
php siro make:crud Product   # Build
php siro why                 # Debug
php siro replay --diff       # Replay
php siro fix                 # Fix & auto-test
```

---

## Features

| Layer | What's included |
|-------|----------------|
| **Router** | O(1) static dispatch, regex dynamic, groups, middleware pipeline, PHP 8 Attributes |
| **ORM** | Active Record, HasOne/HasMany/BelongsTo/BelongsToMany, eager loading, soft deletes, identity map, N+1 detection |
| **Auth** | JWT (HS256/RS256), key rotation, per-token revocation, refresh rotation, API keys, RBAC |
| **Security** | CSP, CSRF, CORS, rate limiting (Redis/file), audit logging, OWASP Top 10 mitigated |
| **CLI** | 80 commands: `make:crud`, `migrate`, `db:why`, `api:why`, `log:replay`, `test:regression`, `fix` |
| **Debug** | Request replay, trace search (IP/path/status/time), `api:why`, `db:why`, N+1 detection, log sanitization |
| **Database** | Query Builder, Schema Builder, migrations, SQLite/MySQL/PostgreSQL, pagination, row locking |
| **Cache** | File + Redis drivers, HMAC-signed config cache |
| **Queue** | DB-based, exponential backoff, priority, timeout, failed job retry |
| **Mail** | SMTP (STARTTLS), sendmail, async queue, HTML + attachments |
| **Validation** | 15+ rules, custom rules + messages, FormRequest |
| **Storage** | Local filesystem, S3-compatible |
| **Events** | Pub/sub, wildcards, one-time listeners, model lifecycle hooks |
| **AI/MCP** | MCP Server built-in — Claude/GPT/Copilot reads your project |

---

## Performance

```
Cold boot (Linux + OPcache):       ~0.5 ms
Cold boot (Windows, no OPcache):   ~2.4 ms
Route dispatch static O(1):        ~0.003 ms (~300K ops/sec)
Full-stack (warm route+response):  ~0.003 ms (~360K ops/sec)
Memory (framework baseline):        ~4 MB
```

Methodology: [BENCHMARK.md](BENCHMARK.md)

---

## Quality

| Gate | Result |
|------|--------|
| PHPStan (Level Max) | **0 errors** |
| Psalm (Level 1 + taint) | **0 errors** |
| Unit + Integration tests | **1,488+ — 0 failures** |
| Fuzz tests | **17,851 — 0 failures** |
| DAST security tests | **157 — 0 failures** |
| Mutation testing | MSI ≥80% |
| Composer audit | **0 vulnerabilities** |

---

## Requirements

- PHP 8.2+
- `ext-pdo`, `ext-json`, `ext-mbstring`

---

## Ecosystem

| Project | Description |
|---------|-------------|
| [SiroPHP](https://github.com/SiroSoft/SiroPHP) | Full project skeleton — 7 controllers, 462 tests, Docker, K8s |
| [siro-mcp-server](https://github.com/SiroSoft/siro-mcp-server) | AI agent integration — Claude/GPT/Copilot |

---

## License

MIT — see [LICENSE](LICENSE).
