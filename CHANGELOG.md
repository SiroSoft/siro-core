# Changelog — siro-core

## v0.35.0 (2026-06-07)

### 🚀 Features
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
