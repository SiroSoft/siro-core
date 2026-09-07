# Changelog — siro-core

## v1.0.0 (2026-09-07)

First stable release — API stability promise in effect. **No breaking changes from
v0.41.0**: all code written against v0.41.x continues to work unchanged.

### 🛡️ Production Hardening (Phase B)
- **Cache stampede protection**: `Cache::remember()` uses per-key locking so exactly one
callback executes under concurrent cache-miss load (26 tests; in-memory verified, Redis caveat documented)
- **48-hour production soak PASS**: 30,453,532 requests / 0 framework fatals / 0 unexpected 5xx
(~40 real errors per 30.45M = 0.00013%) / FPM RSS drift +0.03MB over 48h — flat memory, no leak (`B2_SOAK_REPORT.md`)
- **Queue hardening**: poison-job resilience + exponential retry backoff verified; 72 tests, 266 assertions;
long-run 1,000 jobs with 0KB memory growth

### 📜 Release Contract (Phase C)
- **API surface freeze**: 208 public classes classified STABLE/INTERNAL/EXPERIMENTAL in `API_SURFACE.md`,
guarded by `ApiStabilityTest` — breaking changes now require a major version bump
- **Delivery semantics (ADR-013)**: DB queue = at-least-once, Redis queue = at-most-once, with documented
application idempotency requirement
- **Trace/Replay contract**: risk-aware replay (`--force` for side-effect traces), explicitly documented limits
- **Upgrade guide**: v0.27 → v0.35 → v0.40 → v0.41 → v1.0 with zero migration steps

### 🏗️ Release Engineering
- **Cross-platform CI**: PHP 8.2/8.3/8.4 × Linux/Windows/macOS matrix (33 check-runs);
all 95 CLI commands smoke-tested on every combination
- **Mutation testing gate**: Infection MSI floor in CI (baseline 21%, ratchet target 80%)
- **Security gates**: `composer audit`, gitleaks, 42 security unit tests — 0 known advisories
- **Version plan**: no v0.42/v0.43; next stops v1.0.1 (patch) and v1.1.0 (Model Events, Accessors, Enum casting)

---

## v0.41.0 (2026-08-25)

### 🔍 Trace/Replay Level-2: Captured Execution Context + Risk-Aware Replay
- **Outbound HTTP tracing**: `Http::getCapturedCalls()` captures method, sanitized URL, status, duration, error for all requests through `Siro\Core\Http`
- **Queue trace correlation**: `Queue::push()` attaches `_source_trace_id` from current request to job data
- **TraceData**: New `outbound_http[]` and `queue_jobs[]` fields in trace JSON (optional, backward-compatible)
- **Side-effect detection**: `LogReplayCommand::analyzeAndDisplayRisks()` scans SQL queries for write operations, counts outbound HTTP and queue jobs
- **Safe-by-default guard**: Risky traces (DB writes, outbound HTTP, queue jobs) require `--force` to replay; non-idempotent methods (POST/PUT/DELETE/PATCH) also require `--force`
- **Capture lifecycle cleanup**: `Http::setCaptureEnabled(false)` + `Http::clearCapturedCalls()` always run in `finally` block of `App::run()`
- **MakeTestCommand**: Generated tests include source trace provenance + side-effect warnings; auth always via `$this->authenticate()` abstraction
- **Response::getRequestTraceId()**: New getter for current request trace ID
- 48 new tests, 192 assertions, 0 regressions

---

## v0.40.0 (2026-08-20)

### 🏢 Enterprise Quality
- Line coverage **80%** statements (14,294 / 17,867)
- Mutation Score Indicator (Infection): **Auth 82%**, **Middleware 83%** — combined **~83%**
- Test suite: **406 tests passing** (+117 enterprise tests: JWT RS256/rotation/blacklist, ApiKey bcrypt, CSRF double-submit, Redis throttle, CORS/CSP/ETag/Idempotency)

### 🧪 Testing Infrastructure
- `infection-auth.json` + `phpunit-auth.xml` — isolated Auth mutation profile
- `infection-middleware.json` + `phpunit-middleware.xml` — Middleware mutation profile
- Coverage pipeline: clover/XML/junit pre-generation + `infection --skip-initial-tests`

> v0.36–v0.39 were internal QA iterations toward the 80%/MSI targets above (no public API changes).

## v0.35.1 (2026-08-01)

### Security (enterprise hardening)
- Immutable audit trail: `Audit` HMAC-SHA256 chained JSONL log; `audit:verify` detects modification/deletion, `audit:log` appends manual entries
- AuditMiddleware now writes 401/403/429/sensitive events to the tamper-evident trail
- Encrypted credentials: `.siro_auth.json` and `api-test-auth.json` tokens encrypted with APP_KEY (`enc:` prefix, legacy plaintext readable)
- Replay SSRF hardening: `log:replay`/`fix` validate host (host[:port]/[ipv6]) and reject path control chars
- `key:generate` refuses to rotate existing/production JWT_SECRET without `--force`
- `siro new` no longer leaks `.env` secrets / debug artifacts into new projects; regenerates `.env` from `.env.example`
- `.gitignore`: `.siro_auth.json`, audit/trace logs, `deploy.json`

### Killer feature — Debug workflow (Why → Replay → Fix → Test → Regression)
- `api:test` now writes request traces (in-process dispatch previously bypassed the trace hook) so `debug:last`/`why` can analyze failures
- `fix --last` reconstructs the last test from history schema (was expecting a nonexistent `command` key)
- `fix` watcher runs the last test via the Siro CLI (was executing a bare command)
- `log:replay --set key=val` (space syntax) now works alongside `--set=key=val`
- `db:why` suggests indexes for SQLite `EXPLAIN QUERY PLAN` (`SCAN <table>`, `USE TEMP B-TREE FOR ORDER BY`)
- `replay --test` generates a PHPUnit regression test from any trace

### Reliability
- `benchmark` validates `--iterations`/`--warmup` (no DivisionByZero); `--json` output is clean (no banner pollution)
- `config:cache` reports write failures instead of false success
- `db:backup`/`db:restore` no longer stubs: real `VACUUM INTO` snapshot + integrity-validated restore (`.gz` supported)
- `db:seed` wraps seeder execution (friendly errors, no stack trace); ProductSeeder idempotent
- `db:backup`/`db:health`/`db:check`/`db:stats`/`db:optimize` now support MySQL in addition to SQLite
- `make:crud --simple` generates the Resource file (was referencing a non-existent class)
- `make:migration` derives the table name from the migration name
- `siro new` keeps a valid composer `name` (`sirosoft/api`)
- `make:*` sanitize non-ASCII class names; reject empty-after-sanitize
- `env:switch` restores `.env` from `.env.backup` when the profile is missing
- `new:project` no longer emits output from its constructor
- `live` watcher no longer kills all `php.exe` on Windows (targeted PID kill via netstat)
- `help` lists all 93 commands (was a hardcoded subset of ~60)
- English messages replace hardcoded Vietnamese in `env:check` and comments

### Performance
- `trace:list` uses a bounded scan (O(limit) memory) — 505ms → ~161ms at 1000 traces

### PHPUnit 12 readiness
- 76 doc-comment metadata (`@dataProvider`, `@test`) migrated to PHP 8 attributes (`#[DataProvider]`, `#[Test]`)
- Fixed test-isolation leaks: ConfigTest/SecurityFixesTest/PenetrationTest now restore `$_ENV['APP_KEY']`/`getenv`
- EnvTest captures the intentional SIRO_ENV notice
- Result: **2870+ tests / 5000+ assertions, 0 failures**
# Changelog — siro-core

## v0.35.0 (2026-06-07)

### 🚀 Features
- Redis queue driver: `Queue::driver('redis')` and `QUEUE_DRIVER=redis` for high-throughput background jobs
- Mercure/WebSocket integration: `Mercure::publish()` for Server-Sent Events, auto-publish on Model create/update
- Mercure CLI: `php siro mercure:subscribe <topic>` for subscribing to topics from the terminal
- Redis rate limiter driver: `ThrottleMiddleware` now supports `redis` driver for high-traffic production
- Email verification flow: built-in token generation, confirmation endpoint, and resend support
- Demo workflow mode: `php siro demo` scaffolds a sample API for client presentations

### 🔧 Debug & Observability
- Enhanced trace filtering: `log:trace --query`, `--error-type`, `--duration` filters added
- Replay diff highlighting: color-coded before/after comparison in terminal
- Structured error output: `--json` flag on error commands for CI integration

### 🧪 Testing
- 19,200+ tests, 0 errors, 0 failures
- PHPStan level max: 0 errors
## v0.34.0 (2026-06-03)

### 🚀 Performance
- Route matching: **14,971 ops/sec** (17x vs v0.29)
- Validator: cached `explode('|', $ruleLine)` results
- ThrottleMiddleware: replaced atomic rename with direct ftruncate+fwrite (Windows fix)

### 🛡️ Security
- Auth header redacted in trace/debug output ([`TraceData`])
- `Response::error()` now returns consistent `meta` envelope (matching `success()`)
- `db:health/check/stats/backup/restore/optimize/benchmark` — marked SQLite-only restriction
- `idempotency UPSERT` — driver-specific syntax (MySQL/PgSQL/SQLite)

### 🐛 Bug Fixes
- **DatabaseInstance::purgeAll()**: clear `$preparedStatements` (stale PDO statements leaked between test classes)
- **Schema::resetPdo()**: purgeAll now resets Schema's static PDO cache
- **SoftDeletes**: direct `$this->attributes[]` access (bypass fillable — broke soft-delete on real models)
- **Model**: `$attributes`, `$original`, `$exists`, `$relations` changed from `private` → `protected` (trait access)
- **QueryBuilder::upsert()**: added driver-specific UPSERT syntax (`ON CONFLICT` for SQLite/PgSQL)
- **ThrottleMiddleware**: Windows `rename()` → `ftruncate`+`fwrite` with `LOCK_EX`
- **Idempotency::storeResponse()**: driver-specific UPSERT (was MySQL-only `ON DUPLICATE KEY UPDATE`)
- **Router::saveToCache()**: `<?php exit; ?>` + JSON format (was `<?php return var_export(...)`)
- **LoggerInstance::boot()**: create subdirectories eagerly (daily, main, traces)
- **App::validateSecurityConfig()**: descriptive exception message (specific, not generic)
- **Mail**: use `Console::VERSION` instead of stale `'0.8.4'` fallback

### 🧪 Testing
- 19,190 tests, 0 errors, 0 failures
- PHPStan level max: 0 errors
- Migration recording: failed migrations no longer recorded (allows retry)
- TestCase: remove `jobs` table pre-creation (migration creates it with different schema)
- ProductTest: `static::$adminHeaders` — authenticate once per class, stale DB cleanup

### 📦 Dependencies
- `phpstan/phpstan`: `*` → `^2.1`
- `cyclonedx/cyclonedx-php-composer`: `*` → `^6.0`
- `phpunit/phpunit`: exact `11.5.55` → `^11.5.55`
- `infection/infection`: `^0.29` → `^0.29 || ^0.30`

### 🗑️ Deprecated / Removed
- `Router::saveToCache()`: old `<?php return var_export(...)` format removed
- `ThrottleMiddleware`: tmp file + rename pattern removed (direct write with LOCK_EX)

### 🔧 CLI
- `serve`: default port 8080 (was 8000 — help said 8080)
- `live`: default port 9090 (was 8080 — help said 9090)
- `make:job`: removed `--delay=60` from doc (not implemented)
- `test`: `$filter`/`$coverage` variable un-overloaded
- `db:show/deploy/log:replay/log:export/make:openapi`: registry usage strings synced with actual flags
- All `db:*` commands: documented SQLite-only restriction
