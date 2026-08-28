# Upgrade Guide — SiroPHP v1.0

## Overview

v1.0.0 is the first stable release with an **API stability promise**. From this
version forward, public API changes that break compatibility require a major
version bump. The public method surface is guarded by `ApiStabilityTest`.

**There are no breaking changes from v0.41.0 to v1.0.0.** All code written
against v0.41.x continues to work unchanged.

---

## v0.41.x → v1.0.0

### What changed (non-breaking)

| Area | Change | Migration required |
|------|--------|-------------------|
| **Cache** | `Cache::remember()` now has stampede protection (internal locking) | None — public API unchanged |
| **Queue** | DB queue delivery semantics documented as at-least-once | None — behavior unchanged, documentation clarified |
| **Queue** | Redis queue delivery semantics documented as at-most-once | None — behavior unchanged, documentation clarified |
| **Trace/Replay** | Risky replay requires `--force` (was already in v0.41) | None |
| **CLI** | Command count corrected from 99 to 95 | None — docs updated |
| **Security** | Shell escaping hardened in `serve`, `live`, `fix` commands | None — behavior unchanged |
| **Security** | Deploy `post_deploy` documented as trusted arbitrary shell | None |

### New in v1.0.0 (from v0.41)

| Feature | Description |
|---------|-------------|
| Cache stampede protection | `Cache::remember()` prevents duplicate callback execution under concurrent load |
| Production soak harness | 48-hour soak test infrastructure for runtime validation |
| Cross-platform CI | PHP 8.2/8.3/8.4 × Linux/Windows/macOS matrix |
| CLI smoke tests | All 95 commands verified with data-driven test suite |
| API surface freeze | 208 public classes classified as STABLE/INTERNAL/EXPERIMENTAL |

### Upgrade steps

```bash
# 1. Update the framework
composer update sirosoft/core

# 2. Run the test suite
php siro test

# 3. No migration needed — all public APIs unchanged
```

---

## v0.40.x → v0.41.0

### What changed

| Area | Change | Migration required |
|------|--------|-------------------|
| **Trace/Replay** | Outbound HTTP tracing via `Http::getCapturedCalls()` | None |
| **Trace/Replay** | Queue trace correlation (`_source_trace_id`) | None |
| **Trace/Replay** | Side-effect detection + `--force` for risky replay | Update any automation that replays without `--force` |
| **Trace/Replay** | Generated tests include trace provenance | None |
| **Capture lifecycle** | `Http` capture always cleaned up in `finally` block | None |

### Breaking changes

**`--force` required for risky replays.** If you have scripts that run
`php siro replay <trace>` on traces containing DB writes, outbound HTTP,
or queue jobs, they now require `--force`:

```bash
# Before (v0.40)
php siro replay abc123

# After (v0.41+)
php siro replay abc123 --force   # if trace has side-effect risks
```

GET requests with no detected risks still auto-execute without `--force`.

---

## v0.35.x → v1.0.0

### What changed

| Area | Change |
|------|--------|
| **Security** | HMAC-chained audit trail (`Audit`, `audit:verify`, `audit:log`) |
| **Credentials** | Replay auth tokens encrypted on disk |
| **db:backup/restore** | Real implementation with `.gz` compression |
| **MySQL** | `db:backup/health/check/stats/optimize` support |
| **CLI** | All 95 commands listed in `help` |
| **Quality** | PHPUnit attributes, PHPStan level=max, MSI ~83% |

### Upgrade steps

```bash
composer update sirosoft/core
php siro key:generate --force   # if you rotated APP_KEY
php siro test
php siro audit:verify
```

---

## v0.27.x → v1.0.0

### Behavioral changes

#### Log directory restructured

```
storage/logs/
  daily/2026-05/     ← month-partitioned daily files
  main/              ← cumulative files (rotated at 50MB)
  traces/2026/05/18/ ← date + hash-prefix partitioned
```

**Impact**: Existing log files in the old flat structure are no longer
read by CLI commands (`log:tail`, `log:stats`, etc.).

```bash
# Update custom scripts:
# storage/logs/error-*.log → storage/logs/daily/*/error-*.log
# storage/logs/slow.log → storage/logs/main/slow.log
# storage/logs/traces/*.json → storage/logs/traces/*/*/*/*.json
```

#### Database::execStatement()

```php
// Before
$pdo = Database::connection();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

// After (v1.0)
Database::execStatement('SET FOREIGN_KEY_CHECKS = 0');
```

### New environment variables

```env
LOG_LEVEL=error           # Suppress debug logs in production (default: debug)
LOG_MAX_SIZE_MB=2048      # Total log storage limit in MB (default: 1024)
```

---

## v0.26.x → v0.27.x

- `JWT::encodeAccess()` and `JWT::encodeRefresh()` signatures changed
- `Siro\Core\Auth\JWT` now requires `JWT_SECRET` env var
- `ModelQueryBuilder::where()` signature corrected
- Log directory restructured

---

## API Stability Policy (v1.0+)

- **Patch (1.0.x)**: bug fixes, no public API changes
- **Minor (1.1.x)**: additive public API only (new methods/classes, no removals)
- **Major (2.0)**: breaking public API changes
- Deprecated APIs: documented first, remain functional for one minor version, removed in next major

## Known Limitations (v1.0)

- Redis cache locking: designed but not verified in production Redis environment
- Redis queue: at-most-once delivery (documented, not a bug)
- `pcntl_fork()` multi-worker: Linux/Unix only, Windows falls back to single-process
- `set_time_limit()` timeout: CLI-only, SAPI-dependent
