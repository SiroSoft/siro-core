# Release Notes

## v0.29.6 — ORM Enhancements + Debug Workflow Overhaul (2026-05-24)

### 🔥 New CLI Commands
- **`api:why <METHOD> <path>`**: Trace a specific request — middleware pipeline, SQL queries, timing, exception, possible cause + suggested fix
- **`migrate:reset`**: Rollback all migrations
- **`migrate:refresh`**: Rollback all and re-run migrations
- **`db:why <query_hash>`**: Analyze slow query — EXPLAIN, index suggestion, `--slow` list
- **`test:regression`**: Replay all traces & compare responses — detect status/JSON changes
- **`make:test --from-trace=<id>`**: Generate PHPUnit test from production trace
- **`fix <trace_id>`**: Replay + verify fix nhanh (có watch mode: `php siro fix`)

### 🧩 ORM — 22+ New Features
- **`withCount()` / `loadCount()`**: Query relation counts without loading (+ callback filter support)
- **`has()` / `orHas()`**: Relation count conditions (`has('posts', '>=', 3)`)
- **`whereHas()` nested**: Dot-notation support (`whereHas('user.comments', fn)`)
- **`whereDoesntHave()` / `orWhereDoesntHave()`**: Filter by relation absence
- **`refresh()` / `fresh()`**: Reload model from DB / get fresh instance
- **`loadMissing()`**: Only load relations not yet loaded
- **`touch()`**: Update model's updated_at timestamp
- **`only()` / `append()` / `without()`**: Attribute subset, dynamic appends, skip eager loads
- **`whereColumn()`**: Compare two columns against each other
- **`whereDate/Month/Day/Year/Time()`**: Date-based where clauses
- **`when()` / `unless()`**: Conditional query clauses
- **`distinct()`**: SELECT DISTINCT support
- **`latest()` / `oldest()`**: Order by created_at convenience
- **`orderByDesc()` / `reorder()`**: Convenience ordering methods
- **Column types**: `enum`, `uuid`, `jsonb`, `ipAddress`, `macAddress`
- **Schema**: `comment()`, `renameColumn()`, charset/collation/engine table options

### 🐛 Bug Fixes
- **`log:export`**: Fix "trace not found" — nested trace directory support via `findTraceById()`
- **`log:cleanup`**: Fix recursive trace directory — use `findTraceFiles()` instead of `glob('*.json')`
- **`api:why`**: Fix trace search — use `CommandSupport::findTraceFiles()` (recursive)
- **`log:replay`**: Fix safe mode block GET requests — GET now auto-executes without `--force`
- **`log:replay`**: Fix curl format — compact single-line, JSON body quotes preserved (Windows `escapeshellarg` fix)
- **`log:replay`**: Fix duplicate headers — deduplicate Authorization/Content-Type from `headers` + `auth_header`
- **`debug:health`**: Fix "Log directory missing" — check path from `$basePath` directly
- **`make:test`**: Fix double Test suffix — `OrderTest` → `OrderTest.php` (was `OrderTestTest.php`)
- **`DebugLastCommand`**: Fix SQL duplicate action prefix — deduplicate "UPDATE UPDATE..."
- **`DebugLastCommand`**: Fix middleware tree connector — remove broken `failingIdx` logic

### 💄 Output Polish
- **`php siro why`**: New format — tree connectors (`├`/`└`), "Middleware Pipeline" section, SQL action prefix (SELECT/INSERT/UPDATE), compact timing
- **`log:replay`**: Show headers (deduplicated), body, warning when trace data incomplete
- **`replay --test`**: Generate regression test from trace
- **`replay --diff`**: Compare before/after response with color-coded status

### 📚 Documentation
- `docs/CLI.md`: 72 → 80+ commands, add new debug commands
- `docs/cli-debug-workflow.md`: Complete rewrite with real output examples
- `SiroPHP/docs/api/Debug.md`: Update workflow

### ⚡ Performance
- PHPStan Level Max: **0 errors** (45 baseline)
- All existing tests pass

---

## v0.29.5 — Bug Fixes (2026-05-22)

### 🔧 Bug Fixes
- **`__call()` proxy**: `ModelQueryBuilder::__call()` giờ proxy các method (như `whereNull()`, `whereRaw()`, `whereIn()`, `inRandomOrder()`) xuống parent `QueryBuilder` thay vì chỉ throw exception — sửa lỗi scope lookup fail cho các method không phải scope
- **`getStatusCode()` alias**: Thêm method `getStatusCode()` vào `Response` class, alias cho `statusCode()`, tương thích ngược với Laravel-style code

### 🧪 Testing
- PHP syntax check pass cho cả 2 file

## v0.29.4 — MCP Server Package Support (2026-05-22)

### 🎯 New
- Added `discoverPackageCommands()` to auto-register commands from `extra.siro.commands` in third-party packages
- Added `registerCommands()` bulk registration method
- `sirosoft/mcp-server` package auto-discovers `mcp:serve` command

### 🧪 Testing
- 53 unit/integration tests pass
## v0.29.3 — Schema Inspection & after() Test Coverage (2026-05-22)

### 🧪 Testing
- Added 8 new tests:
  - `testAfterInAlterMysql` — after() generates `AFTER col` in ALTER TABLE (MySQL)
  - `testAfterInAlterMariadb` — after() generates `AFTER col` in ALTER TABLE (MariaDB)
  - `testAfterSilentInCreate` — after() silently ignored in CREATE TABLE (MySQL syntax rule)
  - `testAfterSilentInAlterSqlite` — after() silently ignored on SQLite
  - `testAfterSilentInAlterPgsql` — after() silently ignored on PostgreSQL
  - `testHasColumnWithSqliteMemoryConnection` — Schema::hasColumn() integration test
  - `testGetColumnListingWithSqliteMemoryConnection` — Schema::getColumnListing() integration test
- All 34 Schema tests + 19 CodeQualityFixes tests pass
- PHPStan Level Max: 0 errors

### 📚 Documentation
- Added **Schema Inspection** section to `docs/DATABASE.md` — documents `Schema::hasTable()`, `Schema::hasColumn()`, `Schema::getColumnListing()` with examples
- Clarified `->after()` modifier: MySQL/MariaDB only, ALTER TABLE only

---

## v0.29.2 — Package Auto-Discovery (2026-05-22)

### ✨ New Features
- **Package Auto-Discovery (“composer require” = instant use)**:
  - `Console::discoverPackageCommands()` — reads `vendor/composer/installed.json`, scans `extra.siro.commands` in all installed packages, registers each command automatically
  - `App::discoverPackageProviders()` — reads `vendor/composer/installed.json`, scans `extra.siro.providers` in all installed packages, instantiates and calls `->register($app)` on each

### 📋 Package Convention
Packages declare in their `composer.json`:
```json
{
    "extra": {
        "siro": {
            "commands": {
                "my:command": {
                    "handler": "Vendor\\Package\\MyCommand",
                    "desc": "Description"
                }
            },
            "providers": [
                "Vendor\\Package\\ServiceProvider"
            ]
        }
    }
}
```
No manual registration needed — `php siro` picks them up instantly.

### 📝 Documentation
- Updated `docs/CLI.md` with Package Commands & Auto-Discovery section

### 🛡️ Internal
- PHPStan Level Max: 0 errors
- All tests pass

---

## v0.29.1 — Schema & Migration Enhancements (2026-05-22)

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
