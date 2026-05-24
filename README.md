<div align="center">
  <h1>⚡ Siro Core</h1>
  <p><strong>Production Debugging & Testing Framework for PHP APIs.</strong><br>
  Debug a production bug in 4 commands. Zero runtime dependencies.</p>
</div>

<div align="center">

[![PHP 8.2+](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen.svg)](https://php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen)](https://phpstan.org)
[![Tests](https://img.shields.io/badge/tests-19.000%2B%20pass-brightgreen)](tests/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

</div>

```bash
# 5 seconds → production-ready API with JWT auth
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
  → App::boot() (~1ms)
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
Cold boot:    ~1 ms
Route dispatch (1000 routes):   0.002 ms (O(1))
Full lifecycle:   0.29 ms
Memory per request:   ~2 KB
```

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
