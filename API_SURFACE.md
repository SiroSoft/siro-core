# API Surface — v1.0 Freeze

**Version:** 1.0.0
**Date:** 2026-08-27
**Purpose:** Define the public API contract for SemVer compliance from v1.0 onward.

---

## Classification Legend

| Tag | Meaning | v1.0 Policy |
|-----|---------|-------------|
| **STABLE** | Public API, backward-compatible across 1.x | Will not break without major version |
| **INTERNAL** | May change without notice | Not part of public contract |
| **EXPERIMENTAL** | Shipped but may be removed/changed | Clearly marked, may break in minor |
| **DEPRECATED** | Will be removed in 2.0 | Warning in docs + runtime |

---

## 1. Core Classes (STABLE)

These are the primary developer-facing APIs.

### Application

| Class | Namespace | Public Methods | Classification |
|-------|-----------|---------------|----------------|
| `App` | `Siro\Core` | 7 | STABLE |
| `Console` | `Siro\Core` | 5 | STABLE |
| `Config` | `Siro\Core` | — | STABLE |
| `Env` | `Siro\Core` | — | STABLE |

### HTTP

| Class | Namespace | Public Methods | Classification |
|-------|-----------|---------------|----------------|
| `Request` | `Siro\Core` | 42 | STABLE |
| `Response` | `Siro\Core` | 28 | STABLE |
| `Router` | `Siro\Core` | 26 | STABLE |
| `Route` | `Siro\Core` | — | STABLE |
| `FormRequest` | `Siro\Core` | — | STABLE |
| `UploadedFile` | `Siro\Core` | — | STABLE |
| `Http` | `Siro\Core` | — | STABLE |

### Database

| Class | Namespace | Public Methods | Classification |
|-------|-----------|---------------|----------------|
| `Database` | `Siro\Core` | — | STABLE |
| `DB` | `Siro\Core` | — | STABLE |
| `Model` | `Siro\Core` | — | STABLE |
| `QueryBuilder` | `Siro\Core` | — | STABLE |
| `Schema` | `Siro\Core` | — | STABLE |
| `Collection` | `Siro\Core` | — | STABLE |
| `Resource` | `Siro\Core` | — | STABLE |

### Auth & Security

| Class | Namespace | Public Methods | Classification |
|-------|-----------|---------------|----------------|
| `Auth` | `Siro\Core\Auth` | — | STABLE |
| `JWT` | `Siro\Core\Auth` | — | STABLE |
| `Encrypter` | `Siro\Core` | — | STABLE |
| `Hash` | `Siro\Core` | — | STABLE |

### Services

| Class | Namespace | Public Methods | Classification |
|-------|-----------|---------------|----------------|
| `Cache` | `Siro\Core` | — | STABLE |
| `Mail` | `Siro\Core` | — | STABLE |
| `Queue` | `Siro\Core` | — | STABLE |
| `Event` | `Siro\Core` | — | STABLE |
| `Storage` | `Siro\Core` | — | STABLE |
| `Session` | `Siro\Core` | — | STABLE |
| `Lang` | `Siro\Core` | — | STABLE |
| `Logger` | `Siro\Core` | — | STABLE |

### Utilities

| Class | Namespace | Public Methods | Classification |
|-------|-----------|---------------|----------------|
| `Str` | `Siro\Core` | — | STABLE |
| `Validator` | `Siro\Core` | — | STABLE |
| `URL` | `Siro\Core` | — | STABLE |
| `Helpers` | `Siro\Core` | — | STABLE |

### Observability

| Class | Namespace | Public Methods | Classification |
|-------|-----------|---------------|----------------|
| `Metrics` | `Siro\Core` | — | STABLE |
| `Audit` | `Siro\Core` | — | STABLE |
| `Mercure` | `Siro\Core` | — | STABLE |

### Scheduling

| Class | Namespace | Public Methods | Classification |
|-------|-----------|---------------|----------------|
| `Schedule` | `Siro\Core` | — | STABLE |
| `ScheduleTask` | `Siro\Core` | — | STABLE |

### Exceptions

| Class | Namespace | Classification |
|-------|-----------|----------------|
| `ValidationException` | `Siro\Core` | STABLE |
| `ModelNotFoundException` | `Siro\Core` | STABLE |
| `ExceptionHandlerInterface` | `Siro\Core` | STABLE |

---

## 2. Interfaces (STABLE)

| Interface | Namespace | Classification |
|-----------|-----------|----------------|
| `CommandInterface` | `Siro\Core\Commands` | STABLE |
| `MiddlewareInterface` | `Siro\Core\Middleware` | STABLE |
| `QueueInterface` | `Siro\Core` | STABLE |

---

## 3. Traits (INTERNAL)

| Trait | Namespace | Classification |
|-------|-----------|----------------|
| `CommandSupport` | `Siro\Core\Commands` | INTERNAL |
| `ModelRelations` | `Siro\Core` | INTERNAL |
| `ModelSerialization` | `Siro\Core` | INTERNAL |

---

## 4. CLI Commands (95 total)

### Classification

| Type | Count | Examples |
|------|-------|---------|
| STABLE | 85 | All `make:*`, `migrate*`, `db:*`, `log:*`, `test*`, `route:*`, `queue:*`, `config:*`, `env:*`, `serve`, `test` |
| EXPERIMENTAL | 5 | `tinker`, `demo`, `mercure:subscribe`, `runtime`, `db` |
| INTERNAL | 5 | `debug:health`, `fix`, `optimize`, `doctor`, `new:project` |

### All 95 Commands

| # | Command | Classification |
|---|---------|----------------|
| 1 | `api:test` | STABLE |
| 2 | `api:why` | STABLE |
| 3 | `audit:log` | STABLE |
| 4 | `audit:verify` | STABLE |
| 5 | `benchmark` | STABLE |
| 6 | `config:cache` | STABLE |
| 7 | `config:clear` | STABLE |
| 8 | `db` | EXPERIMENTAL |
| 9 | `db:backup` | STABLE |
| 10 | `db:benchmark` | STABLE |
| 11 | `db:check` | STABLE |
| 12 | `db:explain` | STABLE |
| 13 | `db:health` | STABLE |
| 14 | `db:optimize` | STABLE |
| 15 | `db:restore` | STABLE |
| 16 | `db:seed` | STABLE |
| 17 | `db:show` | STABLE |
| 18 | `db:stats` | STABLE |
| 19 | `db:why` | STABLE |
| 20 | `debug:health` | INTERNAL |
| 21 | `debug:last` | STABLE |
| 22 | `demo` | EXPERIMENTAL |
| 23 | `deploy` | STABLE |
| 24 | `doctor` | INTERNAL |
| 25 | `down` | STABLE |
| 26 | `env:cache` | STABLE |
| 27 | `env:check` | STABLE |
| 28 | `env:switch` | STABLE |
| 29 | `fix` | INTERNAL |
| 30 | `frankenphp:serve` | STABLE |
| 31 | `key:generate` | STABLE |
| 32 | `live` | STABLE |
| 33 | `log:cleanup` | STABLE |
| 34 | `log:export` | STABLE |
| 35 | `log:replay` | STABLE |
| 36 | `log:slow` | STABLE |
| 37 | `log:stats` | STABLE |
| 38 | `log:tail` | STABLE |
| 39 | `log:top` | STABLE |
| 40 | `log:trace` | STABLE |
| 41 | `make:apikey` | STABLE |
| 42 | `make:apikey-table` | STABLE |
| 43 | `make:auth` | STABLE |
| 44 | `make:controller` | STABLE |
| 45 | `make:crud` | STABLE |
| 46 | `make:event` | STABLE |
| 47 | `make:factory` | STABLE |
| 48 | `make:idempotency-table` | STABLE |
| 49 | `make:job` | STABLE |
| 50 | `make:lang` | STABLE |
| 51 | `make:listener` | STABLE |
| 52 | `make:mail` | STABLE |
| 53 | `make:middleware` | STABLE |
| 54 | `make:migration` | STABLE |
| 55 | `make:model` | STABLE |
| 56 | `make:observer` | STABLE |
| 57 | `make:openapi` | STABLE |
| 58 | `make:postman` | STABLE |
| 59 | `make:queue-table` | STABLE |
| 60 | `make:repository` | STABLE |
| 61 | `make:request` | STABLE |
| 62 | `make:resource` | STABLE |
| 63 | `make:rule` | STABLE |
| 64 | `make:seeder` | STABLE |
| 65 | `make:service` | STABLE |
| 66 | `make:test` | STABLE |
| 67 | `mercure:subscribe` | EXPERIMENTAL |
| 68 | `migrate` | STABLE |
| 69 | `migrate:fresh` | STABLE |
| 70 | `migrate:refresh` | STABLE |
| 71 | `migrate:reset` | STABLE |
| 72 | `migrate:rollback` | STABLE |
| 73 | `migrate:status` | STABLE |
| 74 | `new` | STABLE |
| 75 | `new:project` | INTERNAL |
| 76 | `optimize` | INTERNAL |
| 77 | `queue:flush` | STABLE |
| 78 | `queue:retry` | STABLE |
| 79 | `queue:status` | STABLE |
| 80 | `queue:work` | STABLE |
| 81 | `rate:status` | STABLE |
| 82 | `replay` | STABLE |
| 83 | `route:list` | STABLE |
| 84 | `route:rules` | STABLE |
| 85 | `route:search` | STABLE |
| 86 | `runtime` | EXPERIMENTAL |
| 87 | `schedule:run` | STABLE |
| 88 | `serve` | STABLE |
| 89 | `storage:link` | STABLE |
| 90 | `test` | STABLE |
| 91 | `test:regression` | STABLE |
| 92 | `test:run` | STABLE |
| 93 | `tinker` | EXPERIMENTAL |
| 94 | `trace:list` | STABLE |
| 95 | `up` | STABLE |

### Aliases (7)

| Alias | Target | Classification |
|-------|--------|----------------|
| `slow` | `log:slow` | STABLE |
| `why` | `debug:last` | STABLE |
| `traces` | `trace:list` | STABLE |
| `t` | `api:test` | STABLE |
| `tink` | `tinker` | STABLE |
| `make:docs` | `make:openapi --with-swagger` | STABLE |
| `start` | `serve` | STABLE |

---

## 5. Environment Variables (68)

### STABLE (application config)

| Variable | Purpose |
|----------|---------|
| `APP_NAME` | Application name |
| `APP_ENV` | Environment (production/testing/local) |
| `APP_DEBUG` | Debug mode |
| `APP_KEY` | Encryption key |
| `APP_URL` | Application URL |
| `APP_TRUSTED_PROXIES` | Comma-separated trusted proxy IPs |
| `DB_CONNECTION` | Database driver (mysql/pgsql/sqlite) |
| `DB_HOST` | Database host |
| `DB_PORT` | Database port |
| `DB_DATABASE` | Database name |
| `DB_USERNAME` | Database username |
| `DB_PASSWORD` | Database password |
| `JWT_SECRET` | JWT signing secret |
| `JWT_ALGORITHM` | JWT algorithm (HS256/RS256) |
| `JWT_EXPIRY` | JWT token expiry (seconds) |
| `JWT_REFRESH_EXPIRY` | JWT refresh token expiry |
| `QUEUE_CONNECTION` | Queue driver (database/redis) |
| `REDIS_HOST` | Redis host |
| `REDIS_PORT` | Redis port |
| `MAIL_DRIVER` | Mail driver |
| `MAIL_HOST` | SMTP host |
| `MAIL_PORT` | SMTP port |
| `MAIL_USERNAME` | SMTP username |
| `MAIL_PASSWORD` | SMTP password |
| `MAIL_FROM_ADDRESS` | Sender email |
| `MAIL_FROM_NAME` | Sender name |
| `CORS_ALLOWED_ORIGINS` | CORS origins |
| `SESSION_DRIVER` | Session driver |
| `CACHE_DRIVER` | Cache driver |
| `LOG_LEVEL` | Log level |
| `LOG_MAX_SIZE_MB` | Max log size |
| `LOG_RETENTION_DAYS` | Log retention |
| `DB_SLOW_QUERY_THRESHOLD` | Slow query threshold (ms) |
| `RATE_LIMIT` | Requests per minute |
| `SIRO_OPENAPI_ENABLED` | Enable OpenAPI generation |
| `MERCURE_HUB_URL` | Mercure hub URL |
| `MERCURE_JWT_SECRET` | Mercure JWT secret |

### INTERNAL (framework config)

| Variable | Purpose |
|----------|---------|
| `SIRO_BASE_PATH` | Base path override |
| `SIRO_FIX_MAX_ITERATIONS` | Fix command max iterations |
| Various `SIRO_*` | Framework-internal config |

---

## 6. Config Files

| File | Classification |
|------|----------------|
| `config/app.php` | STABLE |
| `config/database.php` | STABLE |
| `config/cache.php` | STABLE |
| `config/mail.php` | STABLE |
| `config/queue.php` | STABLE |
| `config/session.php` | STABLE |
| `config/logging.php` | STABLE |
| `routes/api.php` | STABLE |

---

## 7. Middleware Contracts

| Middleware | Classification |
|-----------|----------------|
| `AuthMiddleware` | STABLE |
| `ThrottleMiddleware` | STABLE |
| `CorsMiddleware` | STABLE |
| `CsrfMiddleware` | STABLE |
| `JsonMiddleware` | STABLE |
| `MaintenanceMiddleware` | STABLE |
| `IdempotencyMiddleware` | STABLE |

---

## 8. Total Surface

| Category | Count | STABLE | INTERNAL | EXPERIMENTAL |
|----------|-------|--------|----------|--------------|
| Classes | 208 | ~180 | ~20 | ~8 |
| Interfaces | 9 | 9 | 0 | 0 |
| Traits | 6 | 0 | 6 | 0 |
| CLI Commands | 95 | 85 | 5 | 5 |
| Aliases | 7 | 7 | 0 | 0 |
| Env Variables | 68 | ~40 | ~28 | 0 |
| Config Files | 8 | 8 | 0 | 0 |
| Middleware | 7 | 7 | 0 | 0 |

---

## 9. SemVer Policy (v1.0+)

- **Patch (1.0.x):** Bug fixes only. No public API changes.
- **Minor (1.1.x):** Additive public API only (new methods/classes). No removals.
- **Major (2.0):** Breaking public API changes.
- **EXPERIMENTAL** items may change or be removed in minor versions with deprecation notice.
- **INTERNAL** items may change without notice.
