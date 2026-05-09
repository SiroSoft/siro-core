# Migration Guide: v0.x → v1.0

**Good news: No breaking changes.** v1.0 maintains full backward compatibility with v0.16.x.

This guide documents what changed in v0.x and how to adapt (if needed).

---

## v0.16.7 Changes (Optional Adoptions)

These are **opt-in features**. Existing code works unchanged.

### Model Relations

If upgrading from before v0.16.7, you can now use relations:

```php
// Define in your Model
class User extends Model
{
    public function phone(): HasOne
    {
        return $this->hasOne(Phone::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

// Usage
$user->phone()        // HasOne instance
$user->roles()       // BelongsToMany instance
$user->roles()->attach($roleId)
$user->roles()->sync([1, 2, 3])
$user->roles()->has($roleId)
$user->roles()->detach($roleId)
$user->roles()->toggle($roleId)
```

### File Upload Validation

New helper methods available:

```php
// Before
if (!$file->getSize() > 5 * 1024 * 1024) { /* reject */ }

// After - more readable
if (!$file->maxSize(5)) { /* reject */ }
if (!$file->isImage()) { /* reject only images */ }
if (!$file->isPdf()) { /* reject non-PDFs */ }
```

### Cursor Pagination

New pagination type for stable results:

```php
// Before - offset pagination (can skip rows with concurrent inserts)
$users = User::paginate(20, page: 2);

// After - cursor pagination (stable, no skipped rows)
$users = User::cursorPaginate(20);
```

---

## v0.15.1 Security Changes

These are **behavior fixes**, not breaking changes:

### Password Confirmation

```php
// Now works correctly with |confirmed rule
$rules = ['password' => 'required|min:8|confirmed'];
// Requires password_confirmation field
```

### DELETE Returns 204 (not 200)

```php
// Response changed from 200 to 204 No Content
// Client code relying on response body may need update
```

### Header Case-Insensitivity

```php
// Before - might have been case-sensitive
$token = $request->header('Authorization');

// Now - works with 'authorization', 'Authorization', etc.
$token = $request->header('authorization');
```

---

## v0.14.0+ CLI Changes

### Command Aliases

| Old | New | Notes |
|-----|-----|-------|
| `php siro start` | `php siro serve` | Both work |
| `php siro why` | `php siro debug:last` | Both work |
| `php siro t` | `php siro api:test` | Shortcut |

### New Commands (opt-in)

- `make:idempotency-table` — Create idempotency keys table
- `make:apikey` — Generate API key for external devs
- `log:trace <id>` — View detailed trace
- `log:export` — Export traces to JSON/CSV

---

## Upgrading Steps

### Step 1: Update Dependency

```bash
composer update sirosoft/core:^1.0
```

### Step 2: Run Tests

```bash
php vendor/bin/phpunit tests/
```

All existing tests should pass. If not, see [Troubleshooting](#troubleshooting).

### Step 3: (Optional) Use New Features

Explore new features at your own pace:
- Model relations
- Cursor pagination
- API Key auth
- Idempotency middleware

---

## Troubleshooting

### Test Failures After Upgrade

1. Clear any cached config: `php siro config:clear`
2. Check error message - likely a type mismatch in assertions
3. Run with verbose: `php vendor/bin/phpunit --verbose`

### Deprecated Warnings

If you see deprecation warnings, check [DEPRECATIONS.md](DEPRECATIONS.md) for migration paths. Currently no deprecations.

### Need Help?

- **Issues**: https://github.com/SiroSoft/siro-core/issues
- **Discussion**: https://github.com/SiroSoft/siro-core/discussions

---

## Summary

| Aspect | Status |
|--------|--------|
| Breaking changes | **None** |
| Backward compatibility | **Full** |
| Test compatibility | **100%** |
| Config compatibility | **100%** |

Upgrade should be seamless. Update composer, run tests, done.