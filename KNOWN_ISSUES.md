# Known Issues & Limitations

## v0.35.1 — Resolved & Current Limitations

### Resolved since v0.26
The following previously-listed issues were fixed in v0.35.1:

| Issue | Fixed in |
|-------|----------|
| `db:backup` / `db:restore` were stubs (reported success, did nothing) | Real `VACUUM INTO` snapshot + validated restore |
| `db:seed` dumped raw PDO stack trace on seeder failure | Wrapped, friendly errors |
| `key:generate` silently rotated production JWT_SECRET | Requires `--force` |
| `siro new` copied real `.env` (secrets) into new projects | Regenerates from `.env.example` |
| `make:crud --simple` referenced a non-existent Resource class | Resource generated |
| `benchmark --iterations=0` crashed (DivisionByZero) | Validated |
| `benchmark --json` polluted with banner text | Clean JSON |
| `config:cache` false success on write failure | Reports error |
| `fix --last` / `api:test` trace gap (Why step missed api:test) | Traces written |
| 30 commands missing from `help` | All 93 listed |
| Vietnamese hardcoded messages | English |

### Current Limitations (v0.35.1)
| Issue | Severity | Workaround |
|-------|----------|------------|
| `db:benchmark` SQLite-only (by design — cross-driver benchmarks aren't comparable) | Low | Compare within a driver |
| Cache stampede — `Cache::remember()` no mutex locking | Medium | Acceptable for most workloads |
| No Prometheus push gateway integration | Low | `/metrics` pull endpoint exists |
| `expose_php`/`X-Powered-By` not disabled by default | Low | `expose_php = Off` in php.ini |
| Trace retention not automatic | Low | `log:cleanup --days=N` |
| Cross-platform CI (Linux/macOS) not yet running | Low | Windows-verified; CI files added, pending GitHub Actions run |
# Known Issues & Limitations

## v0.26 Known Issues

### Observability / Production

| Issue | Severity | Workaround |
|-------|----------|------------|
| Cache stampede — `Cache::remember()` no mutex locking | Medium | Acceptable for most workloads; multiple concurrent cold-key requests all regenerate |
| No health check monitoring integration (Prometheus push gateway) | Low | Use `/metrics` endpoint with Prometheus pull (Metrics::registerRoute) |
| `expose_php` / `X-Powered-By` not disabled by default | Low | Add `expose_php = Off` in php.ini or `header_remove('X-Powered-By')` in bootstrap |
| No CSRF token built-in route | Low | `CsrfMiddleware` exists, but no default GET endpoint to fetch tokens |

### API Documentation

| Issue | Severity | Workaround |
|-------|----------|------------|
| phpDocumentor not installed by default | Low | `composer require --dev phpdocumentor/phpdoc` then `make docs` |
| Fallback docs mode counts vendor/ if not excluded (fixed) | Low | Already patched in v0.26.0 |

## v0.23 Known Issues

### Database

| Issue | Severity | Workaround |
|-------|----------|------------|
| No transaction rollback in CLI | Low | Use `DB::transaction()` manually |
| SQLite foreign keys off by default | Medium | Enable with `PRAGMA foreign_keys = ON` |
| No migrations rollback (only reset) | Low | `migrate:fresh` drops and recreates |

### Auth

| Issue | Severity | Workaround |
|-------|----------|------------|
| No OAuth2/Passport support | Low | Use API Key auth for external devs |
| No multi-tenancy | Medium | Implement in application layer |
| No 2FA built-in | Low | Add manually or use third-party |

### File Upload / Storage

| Issue | Severity | Workaround |
|-------|----------|------------|
| No chunked upload | Medium | Handle in frontend, send as multiple requests |
| S3 driver: basic implementation (no multipart) | Low | Use local storage for files >100MB |
| Max file size limited by PHP.ini | Low | Configure `upload_max_filesize` |

### Queue/Jobs

| Issue | Severity | Workaround |
|-------|----------|------------|
| File-based only (no Redis) | Medium | Use database queue for persistence |
| Delayed jobs via cron only | Low | Use `sendLater()` for basic delays |
| No job retry UI | Low | Check `failed_jobs` table manually |

---

## Architecture Limitations

These are by design, not bugs:

### No Eloquent-style ORM

Siro uses QueryBuilder + Model. Full Eloquem ORM not available.

**Why**: Complexity vs benefit. QueryBuilder covers most use cases.

**Workaround**: Use Model methods + QueryBuilder for complex queries.

### No Web Debug Bar

CLI debugging only. No GUI like Laravel Telescope.

**Why**: Keep zero JS dependencies, framework under 25k lines.

**Workaround**: `log:trace`, `log:replay`, `debug:last`, `why`

### No GraphQL

REST API only.

**Why**: GraphQL adds complexity. REST with cursor pagination covers most needs.

**Workaround**: Use REST + OpenAPI for API docs.

### No WebSocket/SSE

HTTP only. No real-time support.

**Why**: Requires different architecture (event loop, workers).

**Workaround**: Use polling, or external service (Pusher, Ably).

### No Admin Panel

Siro is API-only. No built-in admin UI.

**Why**: Admin panels are app-specific.

**Workaround**: Build with any frontend (Vue, React, Next.js).

---

## Configuration Limits

| Setting | Default | Max | Notes |
|---------|---------|-----|-------|
| Route param length | 255 | 255 | Hard limit |
| Log file size | 50MB | Configurable | Rotation automatic |
| Log retention | 30 days | Configurable | Set `LOG_RETENTION_DAYS` |
| Slow query threshold | 100ms | Configurable | Set `DB_SLOW_QUERY_THRESHOLD` |
| Rate limit (default) | 60/min | 10000 | Per route configurable |
| Upload max size | 8MB | PHP limit | Set in php.ini |

---

## Reporting Issues

Found a bug not listed here?

1. Check existing issues: https://github.com/SiroSoft/siro-core/issues
2. Create new issue with:
   - PHP version
   - Siro core version
   - Reproduction steps
   - Expected vs actual behavior
