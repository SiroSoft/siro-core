# Changelog

## v0.16.1 (2026-05-08)

### 🧪 Testing
- **+84 new tests** for Cache (9), Event (11), Session (10), Logger (4)
- Str (16), Hash (6), Encrypter (8), Collection (16), Database Integration (4)
- **244 tests total** — 360 assertions, 100% pass
- New `tests/integration/` suite with multi-DB connection tests

## v0.16.0 (2026-05-08)

### 🚀 Features
- **DI Container** (`Container.php`) — autowiring, singleton, interface binding
- **Config Repository** (`Config.php`) — dot-notation, file-based caching
- **RBAC** — `auth:admin` role checks in middleware, `make:crud --with-rbac`
- **Session Manager** (`Session.php`) — file/Redis drivers, flash messages
- **Auth Guard** (`AuthGuard.php`) + `UserProvider`/`ModelUserProvider` pattern

### 🔧 Improvements
- 4 middleware moved to core (Auth, Throttle, Cors, Json)
- CSRF middleware updated to use Session instead of raw headers
- Test helpers: `actingAs`, `refreshDatabase`, `assertJsonStructure`
- **162 tests** — PHPStan level 6, zero errors

## v0.15.0 (2026-05-06)

### 🏗️ Schema Builder
- Driver-agnostic Blueprint — write once, run on MySQL/PostgreSQL/SQLite
- Foreign key constraints, schema introspection (`hasTable`, `hasColumn`)

### 🔗 Multi-Database Connections
- Named connections, read/write separation, `Database::connections()`

### 🔐 Encryption
- AES-256-CBC with HMAC integrity check, auto key resolution

### 🌐 HTTP Client
- Zero-dependency curl wrapper — `Http::get()`, `Http::post()`, etc.

### 🔧 Maintenance Mode
- `php siro down` / `php siro up` — 503 with IP allowlist

### 🐘 PostgreSQL Production Support
- Full DSN, `BIGSERIAL`, `RETURNING id`, `RANDOM()`, driver-aware quoting

## v0.14.1 (2026-05-04)

### 🏗️ Service & Repository Pattern
- `make:service`, `make:repository`, `make:crud` with full layers

### 🧪 PHPUnit Test Generation
- `make:test ProductApi` — generates feature tests
- `make:crud` generates 4 test methods per resource

## v0.13.0 (2026-05-01)

- Factory generator, `db:show`, `route:rules`, live reload, deploy system
- Eager loading (`Model::with()`), RS256 JWT, route constraints
- Advanced cron, real queue timeout, optimized throttling

## v0.12.0 (2026-04-29)

- `make:crud` scaffolding, `make:test`, benchmarks, `env:switch`

## v0.11.0 (2026-04-28)

- Service & Repository, eager loading, PHP 8.4 support

## v0.10.0 (2026-04-27)

- Rate limiter, CSRF, config caching, optimize

## v0.9.0 (2026-04-26)

- Queue, mail, events, scheduler, multi-language

## v0.8.0 (2026-04-25)

- Debugging system (trace ID, replay, export), Swagger UI, Postman

## v0.7.0 (2026-04-20)

- Initial release
