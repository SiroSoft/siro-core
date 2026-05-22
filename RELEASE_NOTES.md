# Release Notes

## v0.28.3 — Schema & Migration Enhancements (2026-05-22)

### ✨ New Features
- **`Blueprint::dropIndex()`, `dropUnique()`, `dropForeign()`**: Remove indexes, unique constraints, and foreign keys in ALTER TABLE migrations
- **`Blueprint::primary()` for composite keys**: Non-id columns can now define composite PRIMARY KEY via `$table->primary(['order_id', 'product_id'])`
- **`compileAlter()` full command support**: ALTER TABLE now handles `foreign`, `unique`, `index`, `dropIndex`, `dropForeign` in addition to `addColumn`/`dropColumn`
- **`Schema::table()` now returns array**: Iterates multiple ALTER statements instead of only the first

### 🔧 Bug Fixes
- **PRIMARY KEY not compiled**: `compileCreate()` silently dropped `primary` commands — now handles them (skips duplicate when column type is `id`)
- **DEFAULT false → invalid SQL**: `(string) false` produced empty string causing `DEFAULT ` syntax error — now outputs `DEFAULT 0` / `DEFAULT 1`

### 🧪 Testing
- Added 8 new tests: compositePrimaryKey, defaultBooleanFalse/True, defaultStringValue, idNoDuplicatePrimary, dropIndex, dropUnique, dropForeign, afterModifier
- PHPStan Level Max: 0 errors
- All 28 tests pass

### 📚 Documentation
- Updated `docs/DATABASE.md` with full Blueprint reference table

---

## v0.28.2 — Model DX Enhancement (2026-05-22)

### ✨ New Features
- **Accessors & Mutators**: Transform attributes automatically when getting (`getNameAttribute()`) or setting (`setEmailAttribute()`)
- **Virtual Attributes (Appends)**: Add computed fields like `full_name`, `initials` to JSON/array serialization via `$appends` property
- **DateTime Auto-Formatting**: `'datetime'` and `'date'` casts now return formatted strings instead of DateTime objects, fixing JSON serialization errors
- **Appends Getters/Setters**: `getAppends()`, `setAppends()` for runtime manipulation
- **forceFill bypass**: `forceFill()` now sets attributes directly, bypassing mutators (useful for migrations, bulk ops)

### 🧪 Testing
- Added `tests/unit/AccessorsMutatorsTest.php` with 8 tests, 16 assertions
- PHPStan Level Max: No errors
- All existing Model tests: 38 tests, 46 assertions — 100% pass

---

## v0.28.1 — Composer Plugin Fix (2026-05-12)

### 🔧 Bug Fixes
- **Composer allow-plugins**: Added configuration to allow `infection/extension-installer` plugin
- Fixes `composer install` failures in CI/CD environments with Composer 2.2+

---

## v0.23.0 — The "Số 1" Release (2026-05-12)

### ⚡ Performance
- **Sub-millisecond boot**: Lazy-loaded non-essential services
- **Model refactored**: 908→457 lines, extracted into traits
- **Benchmark**: Container::make 1.67M ops/sec, Response 2.97M ops/sec

### 🛡️ Security
- **CspMiddleware**: Strict CSP with `strict-dynamic` + nonce
- **AuditMiddleware**: Security event logging (401/403/429)
- **File upload MIME validation**: Extension vs actual content type
- **Container circular dependency detection**: MAX_CIRCULAR_DEPTH=64

### 🆕 API Features
- **API Versioning**: `Accept: application/vnd.siro.v2+json` header
- **ETag / Conditional Requests**: Auto `304 Not Modified`
- **Prometheus Metrics**: GET `/metrics` in OpenMetrics format

### 🧪 Testing
- **1,312 tests** passing (886 core + 426 skeleton) — 0 failures
- **New tests**: UploadedFile, FormRequest, ApiKey, EagerLoader
- **SQLite integration tests**: No server required
- **phpunit.xml**: Coverage config (HTML, Clover, text)

### 🔧 Workflow
- **Makefile**: `make test`, `make analyse`, `make audit`, `make check`
- **VSCode**: snippets (30 templates), settings, tasks, launch, extensions
- **AI**: .cursorrules + llms.txt for Copilot/Cursor/Claude
- **CI**: Dependabot + CodeQL + GitHub Actions with PHPStan

---

## v0.22.0 — Final Audit & Zero PHPStan Baseline (2026-05-11)

- All 1,570 PHPStan baseline errors eliminated
- Full type annotations across all Commands (68 files)
- Security: SQL injection fixes, XSS fixes, JWT secret hardening
- Architecture: Model relations, cursor pagination, file upload helpers
- Tests: 868 passing, SoftDeletesTest, SecurityHeadersTest

---

## v0.21.0 — Server-Ready Release (2026-05-10)

- Production server deployment optimized
- 59 CLI commands stable
- JWT access + refresh tokens, RBAC
- MySQL/PostgreSQL/SQLite with migrations
- Model relations (HasOne, HasMany, BelongsTo, BelongsToMany)
- 872 tests passing

---

## v0.20.0 — Developer Experience (2026-05-09)

- Debug workflow: `why`, `fix`, `replay`, `traces`
- API test CLI: `php siro t GET /api/users`
- OpenAPI/Swagger doc generation
- Idempotency keys, API Key auth
- Cursor pagination

---

## Philosophy

SiroPHP is built on five principles:
1. **Simple** — Zero dependencies, ~4MB RAM per request
2. **Fast** — <1ms framework overhead, 3.1M JSON responses/sec
3. **Code Fast** — Scaffold APIs in 2 seconds, not hours
4. **Debug Fast** — CLI-first debugging with trace/replay
5. **Test Fast** — 1,312 tests, 0 failures, built-in coverage
