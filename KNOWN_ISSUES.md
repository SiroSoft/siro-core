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

### No WebSocket (SSE via Mercure supported)

HTTP only. No native WebSocket support.
SSE (Server-Sent Events) is supported via [Mercure](https://mercure.rocks/) protocol (`Mercure.php`).

**Why**: Requires different architecture (event loop, workers).

**Workaround**: Use Mercure hub for SSE, or external WebSocket service (Pusher, Ably).

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
