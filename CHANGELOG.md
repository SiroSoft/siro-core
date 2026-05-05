# Changelog

## v0.15.0 (2026-05-06)

### 🚀 New Features

- **Schema Builder** — `Schema::create()`/`table()`/`drop()` with driver-agnostic Blueprint
- **Multi-Database Connections** — `Database::connection('analytics')`, named connections
- **AES-256 Encryption** — `Encrypter::encrypt()`/`decrypt()` with HMAC integrity
- **HTTP Client** — `Http::get()`/`post()`/`put()`/`patch()`/`delete()` — zero-dependency curl wrapper
- **Str Helper** — 22 methods: `slug()`, `limit()`, `camel()`, `snake()`, `studly()`, `plural()`, etc.
- **Hash Facade** — `Hash::make()`/`check()`/`needsRehash()`
- **Collection Class** — 40+ methods: `map()`, `filter()`, `reduce()`, `where()`, `sort()`, `pluck()`, etc.
- **FormRequest** — Abstract class with `rules()`, `authorize()`, `validated()`
- **Signed URLs** — `URL::signed()`/`validate()`/`validateRequest()` with HMAC
- **Task `withoutOverlapping()`** — Prevent concurrent cron task execution
- **Fake Implementations** — `Queue::fake()`, `Mail::fake()`, `Storage::fake()` with assertions
- **Queue Dashboard** — `Queue::dashboardHtml()` for web monitoring
- **Foreign Key Constraints** — `$table->foreign('col')->references('id')->on('table')->onDelete('cascade')`

### 🛡️ Production Security

- **Log Sanitization** — Passwords, tokens, credit cards, OTPs auto `[REDACTED]` in traces
- **Recursive JSON Sanitize** — Nested objects sanitized deeply (user.token, card.cvv)
- **Log Injection Prevention** — Newlines escaped in all log entries
- **Replay Production Lock** — `--dry-run` only in production, `--force --env=local` required for writes
- **Audit Trail** — Every replay/dry-run/diff logged to `storage/logs/replay-audit.log`
- **Log Protection** — `.htaccess` auto-generated, Nginx check in `doctor`
- **OpenAPI Production Lock** — Disabled by default (`SIRO_OPENAPI_ENABLED=true` to enable)
- **Model/Resource Parsing** — Internal fields stripped (`is_admin`, `token_version`, etc.)

### 💻 CLI & Developer Experience

- **CLI UX Overhaul** — `php siro` shows core workflow (7 commands), layered help
- **`php siro start`** — Interactive onboarding flow
- **`php siro t`** — Short alias for `api:test`
- **`php siro fix`** — Watch code changes & auto-replay last test
- **`php siro why`** — Debug last request (alias for `debug:last`)
- **`php siro replay`** — Replay last trace (with `--edit`/`--diff`)
- **`php siro traces`** — List recent traces (alias for `trace:list`)
- **`php siro down/up`** — Maintenance mode with IP allowlist
- **`make:crud`** onboarding — Shows next steps with `api:test` examples

### 📖 OpenAPI & Documentation

- **Dynamic OpenAPI** — Reads routes, controllers, validation rules from any Siro project
- **35 endpoints, 34 schemas** — Complete API spec generation
- **Swagger UI** — `php siro make:openapi --with-swagger`
- **Postman Collection** — `php siro make:postman` with auto-login pre-request script

### ✅ Testing & Quality

- **PHPStan Level 6** — 0 errors (baseline for type warnings)
- **136 tests, 184 assertions** — Core framework
- **Test assertion helpers** — `assertStatus()`, `assertJson()`, `assertJsonPath()`

### 🔧 Fixes

- `Str::slug()` — Collapse duplicate separators
- `Str::limit()` — Correct truncation with `$end` length accounted
- `Queue.php` — Fix duplicate `assertNotPushed()` method
- `Mail.php` — Suppress `mail()` warning in testing
- `Storage.php` — Add fake mode interceptors
- `SeedCommand.php` — Remove orphaned duplicate code
- `FormRequest.php` — Fix Validator API mismatch
- `URL.php` — Cast query params to string
- `Response::getHeaders()` — Fix return type annotation

---

## v0.14.1 (2026-05-05)

- Service & Repository layer generators (`make:service`, `make:repository`)
- Full CRUD with `make:crud` (Model + Repository + Service + Controller + Resource + Routes + Test)
- PHPUnit test generation (`make:test`)
- DI auto-resolution via Reflection
- Smart validation rule detection in `make:crud`
- README marketing revamp

## v0.14.0 (2026-05-04)

- `debug:last`, `log:top`, `route:search`, `doctor --prod`, `api:test --loop`
- `--simple` CRUD flag, guided CRUD experience

## v0.13.0 (2026-05-03)

- Factory generator (`make:factory`)
- Database inspector (`db:show`)
- Route rules parser (`route:rules`)
- Live dev server (`live`)
- Deployment system (`deploy`)
- PHPStan Level 6

## v0.12.0 (2026-05-02)

- `make:crud` scaffolding
- `make:test` generator
- Benchmarks, watch mode, request collections
- `env:switch`

## v0.11.0 (2026-04-30)

- Service & Repository pattern
- Eager loading, PHP 8.4 support

## v0.10.0 (2026-04-29)

- Rate limiter dashboard, CSRF protection
- Config caching, optimize command

## v0.9.0 (2026-04-28)

- Queue system, mail, events, scheduler
- Multi-language support

## v0.8.0 (2026-04-27)

- Debugging system (trace ID, replay, export)
- OpenAPI/Swagger UI generation
- Postman collection generator

## v0.7.0 (2026-04-26)

- Initial release
- Router, models, JWT auth, validation, migrations, seeders
