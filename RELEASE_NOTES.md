# Release Notes

## v0.21.0 — Server-Ready Release (2026-05-09)

### 🚀 SiroPHP v0.21.0 - Server Deployment Ready

Optimized for production server deployment with enhanced stability and performance.

### Philosophy

SiroPHP is built on five principles:
1. **Simple** — Zero dependencies, ~2MB RAM per request
2. **Fast** — <1ms framework overhead
3. **Code Fast** — Scaffold APIs in minutes, not hours
4. **Debug Fast** — CLI-first debugging for API developers
5. **Test Fast** — Built-in testing with replay capabilities

### What's Server-Ready in v0.21.0

| Component | Status | Notes |
|-----------|--------|-------|
| Router | ✅ Stable | GET/POST/PUT/DELETE/OPTIONS, groups, middleware |
| Request/Response | ✅ Stable | JSON parsing, file uploads, downloads |
| Auth (JWT) | ✅ Stable | Access + refresh tokens, RBAC |
| Database | ✅ Stable | MySQL/PostgreSQL/SQLite, migrations |
| Model Relations | ✅ Stable | HasOne, HasMany, BelongsTo, BelongsToMany |
| CLI (59 commands) | ✅ Stable | Scaffolding, debugging, deployment |
| Testing | ✅ Stable | 872 tests, HTTP assertions, DB assertions |

### New in v0.21.0

#### Model Relations
```php
// HasOne
$user->phone()

// BelongsToMany with attach/detach/sync/has/toggle
$user->roles()->attach($roleId)
$user->roles()->sync([1, 2, 3])
$user->roles()->has($roleId)
```

#### API Reliability
```php
// Idempotency - prevent duplicate requests
// Header: Idempotency-Key: <unique-key>

// API Key Auth - for external developers
// Header: X-Api-Key: <api-key>
```

#### File Upload Helpers
```php
$file->isImage()   // true/false
$file->isPdf()     // true/false
$file->maxSize(5)  // MB, returns bool
$file->hash('sha256')
```

#### Batch Operations
```php
// Update multiple rows
User::whereIn('id', [1, 2, 3])->update(['status' => 'active']);

// Delete multiple rows
User::whereIn('id', [1, 2, 3])->delete();

// Insert multiple rows
User::insertMany([
    ['name' => 'A', 'email' => 'a@test.com'],
    ['name' => 'B', 'email' => 'b@test.com'],
]);
```

#### Cursor Pagination
```php
// Stable pagination for concurrent inserts
$users = User::cursorPaginate(20, after: ['id' => 100, 'created_at' => '2026-01-01']);
```

### Breaking Changes (v0.x → v0.21.0)

**None.** v0.21.0 maintains full backward compatibility with v0.20.x and v0.16.x.

### Deprecations

No deprecated features. All v0.x APIs continue to work.

### Known Limitations

| Limitation | Workaround |
|------------|------------|
| No ORM (like Eloquent) | Use QueryBuilder + Models |
| No Web Debug Bar | Use `log:trace`, `log:replay`, `debug:last` |
| No GraphQL | Use REST + cursor pagination |
| No Redis Queue | Use file-based queue (`php siro queue:work`) |
| No WebSocket/SSE | Use polling or external service |
| No Admin Panel | Build with REST API + any frontend |

### Performance

- **Framework overhead**: <1ms per request
- **Memory**: ~2MB per request
- **Startup**: <50ms cold start
- **CLI**: Instant command execution

Benchmark comparing with other micro-frameworks available in `benchmark/` directory.

### Security

- JWT with HS256/RS256 support
- Password hashing with bcrypt
- SQL injection prevention (prepared statements)
- XSS prevention (output escaping)
- Rate limiting (configurable per endpoint)
- Log sanitization (passwords, tokens auto-redacted)
- CORS middleware built-in

### CLI Commands (59 total)

**Core workflow**: `make:auth`, `make:crud`, `make:controller`, `migrate`, `seed`

**Debug**: `debug:last`, `log:trace`, `log:replay`, `log:top`, `log:tail`, `log:stats`, `log:export`

**Testing**: `api:test`, `test:run`, `t` (shortcut)

**Deployment**: `deploy`, `down`, `up`, `doctor`

**See all**: `php siro list`

### Migrating from v0.x

All v0.20.x and v0.16.x code works unchanged in v0.21.0. See [MIGRATION.md](MIGRATION.md) for detailed guide.

### Support

- **Documentation**: [README.md](README.md)
- **Issues**: https://github.com/SiroSoft/siro-core/issues
- **Discord**: (coming soon)

---

## Changelog

For full history, see [CHANGELOG.md](CHANGELOG.md).
