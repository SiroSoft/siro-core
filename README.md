<div align="center">
  <h1>⚡ Siro Core</h1>
  <p><strong>Production-first API framework for PHP.</strong><br>
  Debug real production requests from your terminal. Zero dependencies.</p>
</div>

<div align="center">

[![PHP 8.2+](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen.svg)](https://php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen)](https://phpstan.org)
[![Tests](https://img.shields.io/badge/tests-19.496%20pass-brightgreen)](tests/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

</div>

---

## What does `api:why` look like?

```bash
php siro api:why POST /api/orders
```

```
  Request
  ────────────────────────────────────────────────────────
  Route:    POST /api/orders
  Status:   ✗ 500
  Duration: 143ms
  Trace ID: siro_a1b2c3d4
  ────────────────────────────────────────────────────────

  Middleware Pipeline
    ├ ✓ AuthMiddleware        2.1ms
    ├ ✓ RateLimitMiddleware   0.8ms
    ├ ✓ CSRFMiddleware        0.4ms
    └ ✗ OrderMiddleware       35ms   ⚠ slow

  SQL Queries
    ├ ▸ SELECT            products  12ms
    ├ ▸ INSERT            orders    8ms
    └ ⚠ UPDATE            inventory  102ms   ⚠ slow
      Total SQL: 122ms

  Exception
    SQLSTATE[23000]: Deadlock found; try restarting transaction

  Possible Cause
    • Concurrent transaction conflict
    • Missing retry logic for deadlock scenarios

  Suggested Fix
    ▸ Wrap transaction in retry loop (max 3 attempts)
    ▸ Reduce transaction scope
    ▸ php siro replay siro_a1b2c3d4 --edit

  Response Source
    └ Controller::store

  Replay
    [r]  php siro replay siro_a1b2c3d4 --force
    [e]  php siro replay siro_a1b2c3d4 --edit
    [d]  php siro replay siro_a1b2c3d4 --diff
    [t]  php siro make:test --from-trace=siro_a1b2c3d4

  ────────────────────────────────────────────────────────
```

One command. Full context. No digging through 5 log files.

---

## Quick start

```bash
composer create-project sirosoft/api my-api
cd my-api
php siro key:generate
php siro make:auth
php siro migrate
php siro serve
```

```http
POST /api/auth/login      {"email":"demo@test.com","password":"secret123"}
GET  /api/products        [Authorization: Bearer <token>]
POST /api/products        {"name":"Laptop","price":999}
```

> Tip: `php siro t GET /api/products` — shorthand for `api:test`, no Postman needed.

---

## Full workflow: build → why → replay → fix → regression

```bash
# 1. Generate CRUD
php siro make:crud Product

# 2. Debug failure — real output
php siro why
```

```
  Request
  ────────────────────────────────────────────────────────
  Route:    POST /api/orders
  Status:   ✗ 500
  Duration: 143ms
  Trace ID: demo_3d0b718f93bace76a91732838336
  ────────────────────────────────────────────────────────

  Middleware Pipeline
  ├ ✓ AuthMiddleware        2.1ms
  ├ ✓ RateLimitMiddleware   0.8ms
  ├ ✓ JsonMiddleware        0.2ms
  ├ ✓ AuditMiddleware       1.1ms
  ├ ✓ MetricsMiddleware     0.3ms
  └ ✗ OrderController       12ms

  SQL Queries
  ├ ▸ SELECT * FROM products WHERE id = ? ...    3ms
  └ ⚠ UPDATE inventory SET stock...              102ms ⚠ slow
    Total SQL: 114ms

  Exception
  └ PDOException: Deadlock found when trying to get lock

  Possible Cause
    • Concurrent transaction conflict
    • Missing retry logic for deadlock scenarios

  Suggested Fix
    ▸ Wrap transaction in retry loop (max 3 attempts)
    ▸ php siro replay demo_... --edit to test fix
```

```bash
# 3. Replay & diff — so sánh trước/sau fix
php siro replay demo_... --diff
```

```
  === BEFORE ===                    === AFTER ===
  Status: 500                       Status: 200
  Body: {"success":false}           Body: {"success":true,"data":{"id":100}}
                                    ✅ Fixed!
```

```bash
# 4. Generate test từ bug thật
php siro make:test --from-trace=demo_...
```

```
Generated: tests/Feature/FromTraceDemo_...Test.php
  php vendor/bin/phpunit --filter=FromTraceDemo_...
  → OK (1 test, 6 assertions)
```

```bash
# 5. Regression — verify không break gì
php siro test:regression --fail
```

No other framework — PHP, Node, Go, Rust, Python, Ruby — has this flow.

---

## Killer features

| Feature | What it does | Why it matters |
|---------|-------------|----------------|
| **`api:why`** | Debug any request by method + path | Instant root cause — no log diving |
| **`replay`** | Replay exact production request locally | Reproduce bugs in 5 seconds |
| **`make:test --from-trace`** | Generate PHPUnit test from real trace | Every bug becomes a permanent regression test |
| **`test:regression`** | Replay all traces, detect regressions | System gets stronger over time |
| **`log:trace`** | Search traces by IP, path, status, time | Find production errors without trace ID |
| **`fix`** | Watch mode — auto re-test on save | Fix and verify in one loop |

---

## But it's also a full API framework

| ⚡ Speed | 🔒 Security | 🧩 Architecture |
|---|---|---|
| Route dispatch: ~0.003ms (static O(1)) | OWASP Top 10 mitigated | DI Container (autowiring) |
| Cold boot: ~2.4ms (Win) / ~0.5ms (Linux+OPcache) | JWT with key rotation | Router O(1) dispatch |
| Zero runtime deps | CSP, CORS, CSRF built-in | ORM with identity map |

| Layer | What you get |
|-------|-------------|
| **Router** | O(1) static dispatch, groups, middleware pipeline, PHP 8 Attributes |
| **ORM** | Active Record, all relation types, eager loading, soft deletes, identity map, N+1 detection |
| **Auth** | JWT (HS256/RS256), key rotation, token revocation, API keys, RBAC |
| **Security** | CSP, CORS, CSRF, rate limiting, audit logging, OWASP Top 10 mitigated |
| **CLI** | 80 commands: `make:crud`, `migrate`, `debug`, `test`, `benchmark` |
| **Debug** | Request replay, trace search, `api:why`, N+1 detection, log sanitization |
| **Testing** | `make:test --from-trace`, `test:regression`, 19K+ tests |
| **Database** | Query Builder, Schema Builder, migrations, SQLite/MySQL/PostgreSQL |
| **Cache** | File + Redis drivers |
| **Queue** | DB-based, exponential backoff, priority, failed job retry |
| **Validation** | 15+ rules, FormRequest |
| **AI/MCP** | MCP Server built-in — Claude/GPT/Copilot reads your project |

---

## Performance

```
Cold boot (Linux + OPcache):     ~0.5 ms
Cold boot (Windows, no OPcache):  ~2.4 ms
Route dispatch static O(1):       ~0.003 ms (~300K ops/sec)
Full-stack (warm route+response): ~0.003 ms (~360K ops/sec)
Memory (framework baseline):       ~4 MB
```

Methodology & hardware: [BENCHMARK.md](BENCHMARK.md)

---

## Quality

| Gate | Result |
|------|--------|
| PHPStan (Level Max) | **0 errors** |
| Psalm (Level 1 + taint) | **0 errors** |
| Unit + Integration tests | **1,488+ — 0 failures** |
| Fuzz tests | **17,851 — 0 failures** |
| DAST security tests | **157 — 0 failures** |
| Mutation testing (Infection) | MSI ≥80% |
| Composer audit | **0 vulnerabilities** |

---

## Philosophy

Traditional frameworks focus on **writing code**.

Siro focuses on **operating APIs in production**.

```bash
# How most frameworks handle errors:
# → Log file → guess → add logging → redeploy → wait → repeat

# How Siro handles errors:
php siro log:trace --status=500 --since=30m
php siro replay siro_a1b2c3d4
php siro fix
php siro test:regression
# → 5 minutes, not 5 hours
```

---

## Requirements

- PHP 8.2+
- `ext-pdo`, `ext-json`, `ext-mbstring`

---

## Ecosystem

| Project | Description |
|---------|-------------|
| [SiroPHP](https://github.com/SiroSoft/SiroPHP) | Full project skeleton — 7 controllers, 45+ tests, Docker |
| [siro-mcp-server](https://github.com/SiroSoft/siro-mcp-server) | AI agent integration — Claude/GPT/Copilot |

---

## License

MIT — see [LICENSE](LICENSE).
