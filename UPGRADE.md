# Upgrade Guide

## v0.27.x → v1.0.0

### Overview

v1.0.0 is the first stable release. No breaking changes from v0.27.x —
all existing code continues to work without modification.

### New Features

| Feature | Description |
|---------|-------------|
| `Database::raw()` | Raw SQL expression support in select/groupBy |
| `Database::execStatement()` | Execute DDL/SET statements directly |
| `orderByRaw()` | ORDER BY with raw SQL expression |
| `groupBy()` variadic | `groupBy('col1', 'col2')` now supported |
| `LOG_LEVEL` env | Set to `error` to suppress debug logs entirely |
| `LOG_MAX_SIZE_MB` env | Total log size limit; oldest files auto-deleted |

### Behavioral Changes

#### Log directory restructured
Logs are now organised into subdirectories:

```
storage/logs/
  daily/2026-05/     ← month-partitioned daily files
  main/              ← cumulative files (rotated at 50MB)
  traces/2026/05/18/ ← date + hash-prefix partitioned
```

**Impact**: Existing log files in the old flat structure are no longer
read by CLI commands (`log:tail`, `log:stats`, etc.). Old files can be
safely moved or deleted after verifying the new structure works.

**If you use custom scripts that read log paths**, update:
- `storage/logs/error-*.log` → `storage/logs/daily/*/error-*.log`
- `storage/logs/slow.log` → `storage/logs/main/slow.log`
- `storage/logs/traces/*.json` → `storage/logs/traces/*/*/*/*.json`

#### Database::execStatement() replaces raw PDO calls
```php
// Before
$pdo = Database::connection();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

// After (v1.0)
Database::execStatement('SET FOREIGN_KEY_CHECKS = 0');
```

#### Model::find() identity map
The identity map now initialises properly, preventing a `count(null)` error
on first access. No code changes needed.

### Removed

- **`demo-v1.0/` tests**: Removed (project no longer maintained).
- **`Dockerfile.dev` tests**: Skipped (use `docker/Dockerfile.frankenphp`).

### New Environment Variables

Add to your `.env` file as needed:

```env
# Suppress debug logs in production (default: debug)
LOG_LEVEL=error

# Total log storage limit in MB (default: 1024)
LOG_MAX_SIZE_MB=2048
```

### Deprecations (none)

No features are deprecated in v1.0.0.

---

## Upgrading from v0.26.x

Follow the v0.27.x → v1.0.0 guide above, plus:

### v0.27.0 changes
- `JWT::encodeAccess()` and `JWT::encodeRefresh()` signatures changed
  (see JWT.md for details).
- `Siro\Core\Auth\JWT` now requires `JWT_SECRET` env var.

### v0.27.1 changes
- `ModelQueryBuilder::where()` now correctly passes 2 or 3 args.
  If you overrode `where()` in a subclass, verify your signature matches.

### v0.27.2 changes
- Log directory restructured (see above).
- `Queue::workAll()` now returns `int` instead of `void`.
