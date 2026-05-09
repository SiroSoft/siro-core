# Changelog

## v0.21.0 (2026-05-10) — Security & Quality Release

### 🐛 Bug Fixes
- **CRITICAL**: Fixed `php://input` double-read (Request.php + JsonMiddleware.php) — JSON body was always empty
- **CRITICAL**: Fixed JWT_SECRET excluded from env cache — cached env bypassed secret loading
- **Fixed**: QueryBuilder cursor pagination — positional bindings (`?`) converted to named bindings (`:param`)
- **Fixed**: QueryBuilder static `$driverName` — per-connection driver detection (multi-db support)
- **Fixed**: CorsMiddleware + Router OPTIONS — respect `CORS_ALLOWED_ORIGINS` env, proper credentials header
- **Fixed**: Response::download() — newline injection in Content-Disposition filename
- **Fixed**: Validator min/max rules — proper type checking (is_int/is_string/is_float)
- **Added**: Event::currentEvent() — track current event name during emit
- **Added**: Lang::count() — count translations in a file
- **Added**: Lang auto-boot — lazy init when BASE_PATH is defined

### 🔧 Improvements
- Env cache now loads excluded secrets (JWT_SECRET, APP_KEY) from .env file
- Cache::requestStatus() now returns array instead of string

### 📊 Testing
- 804 tests, 2178 assertions — all passing
- Added translation test fixtures (en/vi)
- Fixed CacheTest for new requestStatus() return type

## v0.20.0 (2026-05-09) — Production-Ready Release

### 🚀 New Features
- **BenchmarkCommand** - Performance benchmarking CLI tool with iterations and JSON output
- **EnvCacheCommand** - Environment variable caching for production optimization
- **SecurityTest Suite** - Comprehensive security testing (30+ tests)
- **HTML Homepage** - Browser-friendly landing page at root path with content negotiation

### 🔧 Improvements
- Version synchronization across all files (composer.json, Console.php, README, RELEASE_NOTES)
- Enhanced root route with better browser/API client detection
- Added .htaccess for Apache web server support
- Improved documentation with version consistency

### 📊 Testing
- Security test suite added (30+ security scenarios)
- Benchmark command with configurable iterations
- All existing tests maintained (604+ unit tests)

### 🐛 Bug Fixes
- Fixed version inconsistencies in RELEASE_NOTES.md
- Fixed missing strict_types declarations in application controllers
- Fixed API response version references

## v0.16.1 (2026-05-08)

### 🧪 Testing
- **+340 new tests** for StrExtensions (35), ValidatorCombinations (20), ResponseHeaders (14)
- RequestTypedInput (37), MassAssignment (16), Storage (20), Queue (18), Mail (16)
- Cache (9), Event (11), Session (10), Logger (4), Hash (6), Encrypter (8)
- Collection (16), Database Integration (4), Lang (20), ConfigAdvanced (17)
- EventAdvanced (17), UploadedFile (14) and more
- **604 tests total** — 1924 assertions, 100% pass
- All HTTP tests skipped (require external network/SSL)
- New `tests/unit/` suite with focused component tests

### 🔧 Fixes
- `Helpers.php`: Added `dd()` and `dump()` functions for PHPStan
- `App.php`: Fixed `$request might not be defined` errors
- `phpstan-baseline.neon`: Removed 4 obsolete entries

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
