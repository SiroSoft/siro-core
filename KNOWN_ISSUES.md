# Known Issues & Limitations

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
