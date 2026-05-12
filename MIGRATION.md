# Migration Guide

**No breaking changes.** All v0.x versions maintain full backward compatibility.

---

## v0.22 → v0.23 (Current)

No migration needed. Just update:

```bash
composer update sirosoft/core:^0.23
```

### New Features Available (opt-in)
- **API Versioning**: `Accept: application/vnd.siro.v2+json` header
- **ETag / Conditional Requests**: Auto `304 Not Modified` for cached responses
- **Prometheus Metrics**: GET `/metrics` endpoint
- **CSP Middleware**: Strict Content-Security-Policy with nonce
- **Audit Middleware**: Security event logging for 401/403/429
- **Container Circular Dependency Detection**: Automatic detection with max depth 64

### Improvements (automatic)
- **Model refactored**: 908→457 lines (same API, no change needed)
- **Auth middleware cached**: User cached per request (DB query once, not per middleware)
- **File upload MIME validation**: Extension vs actual MIME type cross-check

---

## v0.16 → v0.22

### Model Relations (opt-in)

```php
class User extends Model
{
    public function phone(): HasOne
    {
        return $this->hasOne(Phone::class);
    }
}
```

### File Upload Validation

```php
$file->isImage()  // Check MIME type
$file->isPdf()    // Check MIME type
$file->hash()     // SHA-256 hash
```

### CSRF Protection

Add to routes:
```php
->middleware([CsrfMiddleware::class])
```

---

## v0.15 → v0.16

### DELETE Returns 204 (not 200)

Client code relying on response body may need update.

### Header Case-Insensitivity

All header lookups now case-insensitive.

### Rate Limiting

New `throttle:60,1` middleware syntax.

---

## Upgrading Steps

```bash
# 1. Update
composer update sirosoft/core

# 2. Run tests
php vendor/bin/phpunit

# 3. (Optional) Clear caches
php siro config:clear
php siro optimize
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Tests fail after upgrade | `php siro config:clear`, then `composer dump-autoload` |
| Routes not found | Clear route cache: delete `storage/framework/routes.php` |
| Need help? | https://github.com/SiroSoft/siro-core/issues |
