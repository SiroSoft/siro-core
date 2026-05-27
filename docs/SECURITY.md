---
title: S EC UR IT Y
description: SiroPHP S EC UR IT Y reference
sidebar_position: 11
sidebar_label: S EC UR IT Y
---

# Security Guide

SiroPHP is designed with defense-in-depth. Every layer — from JWT authentication to output encoding, rate limiting, and audit logging — is built to be secure by default.

> See [JWT.md](JWT.md) for full JWT implementation details.

---

## Authentication & Authorization

### JWT Auth

JWT is the primary authentication mechanism. SiroPHP enforces strict token validation:

- **Algorithm pinning**: The server-configured `JWT_ALGORITHM` (HS256 or RS256) is always used for verification. The `alg` header in the token itself is never trusted. This prevents alg confusion attacks.
- **Key rotation**: `JWT::rotateKey()` increments `JWT_KEY_VERSION` and supports dual verification against the previous secret during the rotation window.
- **JTI blacklist**: Each token carries a unique `jti` (JWT ID). Revoked tokens are stored in `jti_blacklist:*` cache entries and checked at decode time via `JWT::isJtiBlacklisted()`.
- **Claim validation**: Requires `sub` (user ID), `ver` (token version), `iat`, `exp`, and `type`. Tokens with missing claims are rejected.
- **Secret strength**: `JWT::secret()` enforces a minimum 32-character secret and rejects placeholder values.

```php
// Encode an access token (1-hour TTL)
$token = JWT::encodeAccess($userId, $tokenVersion);

// Encode a refresh token (7-day TTL)
$refresh = JWT::encodeRefresh($userId, $tokenVersion);

// Decode and verify (throws RuntimeException on failure)
$claims = JWT::decode($token);

// Blacklist a JTI during logout
JWT::blacklistJti($jti, $expiresAt);

// Rotate secret (increments version)
JWT::rotateKey($newSecret);
```

```env
JWT_SECRET=your-super-secret-key-minimum-32-chars-long
JWT_ALGORITHM=HS256

# For RS256 asymmetric signing:
# JWT_ALGORITHM=RS256
# JWT_PRIVATE_KEY_PATH=/path/to/private.pem
# JWT_PUBLIC_KEY_PATH=/path/to/public.pem
```

### AuthMiddleware — Role-Based Access

`AuthMiddleware` decodes the Bearer token, looks up the user, checks `token_version` match, and optionally enforces roles:

```php
Route::get('/admin', [Controller::class, 'admin'])
    ->middleware(['auth:admin']);

Route::put('/profile', [Controller::class, 'update'])
    ->middleware(['auth:user,admin']); // multiple allowed roles
```

**Checks performed:**
1. Bearer token present and valid JWT
2. Token not expired
3. User exists and has `status = 1`
4. `token_version` matches the database
5. User role matches one of the required roles (403 if not)

Failed authentication is logged via `Logger::error()` with IP and path for security monitoring.

### API Key Auth

For external developer access, `ApiKeyMiddleware` validates `X-Api-Key` header against stored keys in the `api_keys` table:

```php
Route::get('/api/external/data', [Controller::class, 'data'])
    ->middleware(['apikey:read']);
```

Keys can be scoped (e.g. `read,write`) and expire. `make:apikey` generates them.

---

## CSRF Protection

SiroPHP implements a dual-strategy CSRF defense based on the request context:

### Session-based (Web Forms)

When a session is active, `CsrfMiddleware` uses a per-session token stored via `Session`:

```php
// In your form
echo CsrfMiddleware::field();
// <input type="hidden" name="_csrf_token" value="abc123...">

// In your layout head
echo CsrfMiddleware::metaTag();
// <meta name="csrf-token" content="abc123...">
```

The token is rotated after each successful validation to prevent reuse.

```php
// Via AJAX
fetch('/api/data', {
    method: 'POST',
    headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    }
});
```

### Double-Submit Cookie (Stateless API)

When no session is available, the middleware falls back to comparing a `csrf_token` cookie with the `X-CSRF-TOKEN` or `X-XSRF-TOKEN` header using `hash_equals()` to prevent timing attacks.

```php
Route::post('/api/orders', [OrderController::class, 'store'])
    ->middleware([CsrfMiddleware::class]);
```

Both strategies return HTTP 419 on mismatch.

---

## CORS

`CorsMiddleware` is configured via environment variables:

```env
CORS_ALLOWED_ORIGINS=https://example.com,https://app.example.com
CORS_ALLOWED_METHODS=GET,POST,PUT,DELETE,OPTIONS
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Requested-With
```

**Key behaviors:**
- `OPTIONS` preflight requests receive CORS headers and return 204 immediately.
- When `CORS_ALLOWED_ORIGINS=*`, `Access-Control-Allow-Credentials: false` is sent (wildcard origins cannot use credentials).
- When specific origins are listed, the middleware validates the `Origin` header against the whitelist and sets `Access-Control-Allow-Credentials: true`.
- Exposed headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`, `X-Siro-Trace-Id`.
- `Vary: Origin` is set to prevent CDN caching issues.

```php
Route::get('/api/public', [Controller::class, 'index'])
    ->middleware([CorsMiddleware::class]);
```

---

## Content Security Policy (CSP)

`CspMiddleware` enforces a strict default policy to prevent XSS and data injection:

```
default-src 'self'; script-src 'strict-dynamic' 'nonce-{nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'
```

**Features:**
- Uses `strict-dynamic` for maximum security with modern browsers.
- A per-request cryptographic `nonce` is generated via `random_bytes(16)` and injected into the policy.
- Retrieve the nonce for inline scripts:

```php
<script nonce="<?= CspMiddleware::nonce() ?>">
    // your inline script
</script>
```

- Customize via `CSP_POLICY` env var.
- Also sets `X-Content-Type-Options: nosniff` and `X-Frame-Options: DENY`.

---

## Rate Limiting

`ThrottleMiddleware` implements a sliding-window rate limiter:

```php
// 5 requests per minute on login
Route::post('/auth/login', [AuthController::class, 'login'])
    ->throttle(5, 1);

// 60 requests per minute on API
Route::get('/api/users', [UserController::class, 'index'])
    ->throttle(60, 1);
```

### Backend Strategy

1. **Redis** (primary): Uses atomic Lua scripting (`INCR` + `EXPIRE`) for race-condition-free counting. Key format: `rate:<ip>:<METHOD:path>`.
2. **File fallback** (default when Redis unavailable): Uses `flock()`-locked JSON files in `storage/rate_limit/`.

### Fallback Modes

Configured via `THROTTLE_FALLBACK` env:

| Value | Behavior |
|---|---|
| `file` (default) | File-based rate limiting |
| `disabled` | Bypass rate limiting entirely |
| `fail_closed` | Reject all requests (429) |

### Response Headers

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1715000000
Retry-After: 120
```

### Monitor

```bash
php siro rate:status
# Shows all active rate limit entries with counts, TTL, and blocked status
```

---

## IDOR Protection

Insecure Direct Object Reference (IDOR) is prevented through `AuthMiddleware` role-based access:

```php
Route::get('/api/users/{id}', [UserController::class, 'show'])
    ->middleware(['auth:admin']);
```

- Role enforcement ensures only authorized roles can access a resource.
- The authenticated user object is attached to the request via `$request->setUser()` and is accessible in controllers:

```php
$user = $request->user();
if ((int) $user['id'] !== (int) $id) {
    return Response::json(['error' => 'Forbidden'], 403);
}
```

---

## SQL Injection

**Fully mitigated.** All database queries use PDO prepared statements with parameterized bindings. No user input is ever interpolated into SQL strings.

```php
// Safe — parameterized
DB::table('users')
    ->where('email', $request->input('email'))
    ->first();

// Safe — positional bindings in raw queries
DB::select('SELECT * FROM users WHERE id = ?', [$id]);
```

**Protected surfaces:**
- `QueryBuilder` — all `where`, `join`, `orderBy`, `having`, `groupBy` methods
- `Model` CRUD — `find`, `create`, `update`, `delete`
- `Schema/Blueprint` — column definitions, indices
- `DB::raw()` — only with explicit bindings

---

## XSS

All output is contextually encoded to prevent cross-site scripting:

- `Response::json()` — JSON responses are automatically encoded; no HTML context risk.
- For HTML views, use `htmlspecialchars()` with `ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8:

```php
echo htmlspecialchars($userInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
```

- The CSP middleware provides a second layer of defense via `strict-dynamic` and nonces, preventing inline script execution even if encoding fails.

---

## Path Traversal

`Storage::localPath()` applies recursive sanitization to prevent directory traversal:

```php
// Input: "../../../etc/passwd"
// Step 1: Replace ../ .\ / with DIRECTORY_SEPARATOR
// Step 2: Filter out '..' and '.' segments
// Step 3: Realpath check — resolved path must start with allowed directory
// Step 4: String-level prefix check for non-existent files
// Throws RuntimeException on traversal attempt
$safePath = Storage::localPath($userProvidedPath);
```

**Protection layers:**
1. Recursive `str_replace` of traversal sequences (`../`, `..\\`, `./`)
2. Segment filtering (drops `..`, `.`, empty segments)
3. `realpath()` validation — final path must be under `storage/app/`
4. String-level prefix check as defense-in-depth for new files

---

## Logger Sanitization

The logger automatically redacts sensitive data before writing to disk:

### Redacted Fields

| Category | Fields |
|---|---|
| Body | `password`, `token`, `otp`, `secret`, `credit_card`, `card_number`, `cvv`, `pin`, `ssn`, `passport` |
| Headers | `authorization`, `cookie`, `x-api-key`, `x-csrf-token`, `session-id` |
| Query | `token`, `key`, `secret`, `api_key`, `code` |

### Methods

```php
// String sanitization (error messages, debug output)
Logger::sanitize("password=secret123");
// => "password=[REDACTED]"

// JSON body sanitization (traces)
Logger::sanitizeJsonBody('{"password":"secret123"}');
// => '{"password":"[REDACTED]"}'

// Header sanitization
Logger::sanitizeHeaders(['Authorization' => 'Bearer abc...']);
// => ['Authorization' => '[REDACTED]']
```

Credit card patterns (13-19 digit numbers) are also redacted: `[REDACTED-CARD]`.

---

## Security Headers

### Default Headers

Every response from `CspMiddleware` includes:

```http
Content-Security-Policy: <configured policy>
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
```

### Additional Headers

SiroPHP applies these security headers at the application level:

| Header | Value | Purpose |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME type sniffing |
| `X-Frame-Options` | `DENY` | Prevents clickjacking |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Enforces HTTPS (recommended — add via middleware) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Controls referrer leakage |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=()` | Restricts browser API access |

### Custom Headers

```php
return Response::json($data)->withHeaders([
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    'Permissions-Policy' => 'geolocation=(), microphone=()',
]);
```

---

## Audit Logging

`AuditMiddleware` logs security-relevant events to a dedicated `storage/logs/security-YYYY-MM-DD.log` file in a SIEM-ready JSON format:

```php
Route::post('/admin/settings', [Controller::class, 'update'])
    ->middleware([AuditMiddleware::class . ':sensitive']);
```

### Event Types

| Event | Trigger | Data |
|---|---|---|
| `auth.failed` | HTTP 401 response | IP, method, path, user-agent |
| `unauthorized.access` | HTTP 403 response | IP, user ID, role, path |
| `rate_limit.exceeded` | HTTP 429 response | IP, path, method |
| `sensitive.operation` | Context `sensitive` flag | User ID, action, IP |

### Log Format

```
[2026-05-13 10:30:00] [SECURITY] auth.failed {"ip":"192.168.1.1","method":"POST","path":"/auth/login","user_agent":"curl/8.0"}
```

`Logger::security()` always writes to the `security` log file regardless of log level configuration, ensuring audit trail integrity.

### Log Directory Protection

The logger automatically protects log directories:
- `.htaccess` with `Deny from all` (Apache)
- `nginx-deny.conf` with `deny all;` (nginx)
- `web.config` with request filtering (IIS)

---

## Environment & Key Security

```bash
php siro key:generate           # Generates APP_KEY (base64:32-bytes)
php siro env:check              # Validates all required vars
```

**Checks performed by `env:check`:**
- `.env` file exists
- Required variables are set
- `JWT_SECRET` minimum 32 characters
- `APP_DEBUG` is `false` in production
- Required PHP extensions loaded
- Storage directories writable
- MySQL version >= 8.0 (JSON column support)

---

## Mass Assignment Protection

Models require explicit `$fillable` arrays. Unauthorized fields are blocked at the framework level:

```php
class User extends Model {
    protected array $fillable = ['name', 'email'];
}

// Only 'name' and 'email' will be set
User::create($request->all());
```

An `E_USER_WARNING` is triggered if `$fillable` is empty.

---

## Encryption

`Encrypter` provides AES-256-CBC encryption with HMAC integrity verification:

```php
$encrypted = Encrypter::encrypt($sensitiveData);
$decrypted = Encrypter::decrypt($encrypted);
```

Use for: credit card numbers, PII, API keys stored in DB, SSNs.
Do NOT use for: passwords (use `Hash::make` with bcrypt cost 12).

---

## Best Practices Checklist

### Pre-Deployment

```bash
php siro doctor --prod           # Full production readiness check
php siro env:check               # Validate environment
php siro optimize                # Cache config, routes, env
php siro storage:link            # Create public storage symlink
```

### Checklist

- [ ] `APP_DEBUG=false` in production
- [ ] `JWT_SECRET` is 32+ random characters, not a placeholder
- [ ] JWT algorithm is pinned server-side (never trust token header)
- [ ] CSRF middleware applied to all state-changing routes
- [ ] CORS origins restricted (not `*` for credential-based auth)
- [ ] CSP policy is strict (uses `strict-dynamic`)
- [ ] Rate limiting applied to auth endpoints (5/min or lower)
- [ ] AuthMiddleware roles specified for protected routes
- [ ] All models have explicit `$fillable` arrays
- [ ] Storage directory permissions: `755`
- [ ] HTTPS enforced (HSTS header configured)
- [ ] AuditMiddleware applied to sensitive operations
- [ ] Log retention policy configured (`LOG_RETENTION_DAYS`)
- [ ] `.env` file is in `.gitignore`
- [ ] Throttle fallback is not `disabled` in production
- [ ] Log directories are web-inaccessible (`.htaccess`/`nginx-deny.conf`)
- [ ] API keys are scoped and have expiration dates

### Incident Response

```bash
php siro down --allow=YOUR_IP          # Maintenance mode
# Rotate all tokens:
php siro key:generate                   # New JWT secret
# Investigate:
php siro log:trace --status=500        # Find errors
php siro log:export --days=7 --format=json --output=incident.json
php siro rate:status                   # Check for abuse
php siro up                            # Restore
```

---

## Reporting Vulnerabilities

Report security issues to **security@sirosoft.com**. Response within 48 hours.
