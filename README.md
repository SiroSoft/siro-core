<div align="center">
  <h1>⚡ Siro Core</h1>
  <p><strong>Production Debugging & Testing Framework for PHP APIs.</strong><br>
  Zero dependencies · OWASP Top 10 mitigated · PHPStan Level Max (true 0)</p>
</div>

<div align="center">

[![PHP 8.2+](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen.svg)](https://php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen)](https://phpstan.org)
[![Security Audit](https://img.shields.io/badge/audit-9.0%2F10-brightgreen)](docs/AUDIT_SUMMARY.md)
[![Tests](https://img.shields.io/badge/tests-19.000%2B%20pass-brightgreen)](tests/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

</div>

```bash
# 5 seconds → production-ready API with JWT auth + CRUD + tests
composer create-project sirosoft/api my-api && cd my-api && php siro serve
```

---

## Debug a production bug in 4 commands

**Every framework logs errors. Siro lets you replay them.**

```bash
# 1. Search — customer chỉ nhớ "lúc đặt hàng bị lỗi"
php siro log:trace --path=/api/orders --status=500 --since=30m

# 2. Replay — tái hiện request thật (dry-run, safe)
php siro log:replay trace_abc123 --diff

# 3. Generate regression test từ bug thật
php siro make:test --from-trace=trace_abc123

# 4. Verify fix với toàn bộ regression suite
php siro test:regression
```

**This is not a feature list. This is a workflow.**  
No other framework — PHP, Node, Go, Rust, Python, Ruby — has this flow.

---

## But it's also a full API framework

| ⚡ **Speed** | 🔒 **Security** | 🧩 **Architecture** |
|---|---|---|
| Route dispatch: ~0.003ms (static O(1)) | OWASP Top 10 mitigated | DI Container (autowiring) |
| Cold boot: ~2.4ms (Win) / ~0.5ms (Linux+OPcache) | 7 rounds audit — 9.0/10 | Router O(1) dispatch |
| Zero runtime deps | JWT with key rotation | ORM with identity map |
| Full-stack throughput: ~410K req/s | CSP, CORS, CSRF built-in | 80 CLI commands |

---

## Why `api:why`

```bash
php siro api:why POST /api/orders
```

```
Request: POST /api/orders
Status: 500
Duration: 143ms

Middleware Pipeline
├── AuthMiddleware        2.1ms ✅
├── RateLimitMiddleware   0.8ms ✅
├── CSRFMiddleware        0.4ms ✅
└── OrderMiddleware       35ms ❌

SQL Queries
├── SELECT products       12ms
├── INSERT orders         8ms
└── UPDATE inventory      102ms ⚠ slow

Exception
└── SQLSTATE[23000]: Deadlock found

Response Source
└── app/Controllers/OrderController.php:82
```

One command. Full context. No digging through 5 log files.

---

## Quick start

```bash
# 1 command = full project
composer create-project sirosoft/api my-api
cd my-api

# Chạy ngay — zero config
php siro serve
```

```http
POST /api/auth/login      {"email":"demo@test.com","password":"secret123"}
GET  /api/products        [Authorization: Bearer <token>]
POST /api/products        {"name":"Laptop","price":999}
```

---

## Architecture

```
public/index.php
  → App::boot() (~2.4ms Win, ~0.5ms Linux+OPcache)
    → Router::dispatch()
      → Middleware chain (13 layers)
        → Controller → Service → Repository → Model/DB
          → Resource transformer → JSON Response

Zero external runtime dependencies.
Zero config file parsing at boot.
Zero heavy bootstrapping.
```

---

## Core features

Siro is a full API framework. Not just a debug tool.

| Layer | What you get |
|-------|-------------|
| **Router** | O(1) static dispatch, groups, middleware pipeline, PHP 8 Attributes |
| **ORM** | Active Record, HasOne/HasMany/BelongsTo/BelongsToMany, eager loading, withCount, whereHas nested, soft deletes, identity map, N+1 detection |
| **Auth** | JWT (HS256/RS256), key rotation, per-token revocation, refresh rotation, API keys, RBAC |
| **Security** | CSP, CORS, CSRF, rate limiting, audit logging, OWASP Top 10 mitigated |
| **CLI** | 80 commands: make CRUD, migrate, debug, test, benchmark |
| **Debug** | Request replay, trace search (by IP/path/error), `php siro why`, N+1 detection, slow query detection, log sanitization |
| **Testing** | Transaction isolation, TestResponse (fluent assertions), `make:test --from-trace`, `test:regression` |
| **Database** | Query Builder, Schema Builder, migrations, SQLite/MySQL/PostgreSQL |
| **Cache** | File + Redis drivers, HMAC-signed config cache |
| **Queue** | DB-based, exponential backoff, priority, failed job retry |
| **Validation** | 15+ rules, custom rules + messages, FormRequest |
| **Storage** | Local filesystem, S3-compatible |
| **AI/MCP** | MCP Server built-in — Claude/GPT/Copilot reads your project |

---

## Performance

```
Cold boot (Linux + OPcache):   ~0.5 ms
Cold boot (Windows, no OPcache): ~2.4 ms
Route dispatch static O(1):      ~0.003 ms (361K ops/sec)
Full-stack (warm route+response): ~0.002 ms (403K ops/sec)
Full-stack (cold boot + route):   ~2.4 ms (410K req/sec)
Memory (baseline PHP process):     ~4 MB
```

> Boot time dominates cold requests. In production (OPcache + FrankenPHP),
> boot is ~0.5ms once per worker — all subsequent requests skip boot.

Methodology & hardware: [BENCHMARK.md](BENCHMARK.md)

---

## Quality

| Gate | Result |
|------|--------|
| PHPStan (Level Max) | **0 errors** |
| Psalm (Level 1 + taint) | **0 errors** |
| Unit + Integration tests | **1,030+ — 0 failures** |
| Fuzz tests | **17,851 — 0 failures** |
| DAST security tests | **157 — 0 failures** |
| Mutation testing (Infection) | MSI ≥80% |
| Composer audit | **0 vulnerabilities** |
| 7 rounds security audit | **9.0/10** |

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
