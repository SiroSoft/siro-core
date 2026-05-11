# Security Guide

## Overview

SiroPHP is designed with security-first principles. This document outlines all security features, best practices, and known attack vectors that the framework protects against.

---

## 🔐 Authentication & Authorization

### JWT Token Security

**Token Structure:**
- Access tokens: 1-hour TTL (short-lived)
- Refresh tokens: 7-day TTL (long-lived)
- Token versioning for instant revocation
- JTI (JWT ID) uniqueness enforcement

**Best Practices:**
```env
# Use strong JWT secret (minimum 32 characters)
JWT_SECRET=your-super-secret-key-minimum-32-chars-long

# For production, use RS256 asymmetric signing
JWT_ALGORITHM=RS256
JWT_PUBLIC_KEY=/path/to/public.pem
JWT_PRIVATE_KEY=/path/to/private.pem
```

**Security Features:**
- ✅ Automatic token rotation on refresh
- ✅ Token blacklisting via version tracking
- ✅ RS256 support for enhanced security
- ✅ Secure storage of refresh tokens in database

### RBAC (Role-Based Access Control)

```php
// Protect routes by role
Route::get('/admin/dashboard', [AdminController::class, 'index'])
    ->middleware(['auth:admin']);

Route::post('/users', [UserController::class, 'store'])
    ->middleware(['auth:user,admin']);
```

**Middleware Checks:**
1. Valid JWT token present
2. Token not expired
3. User role matches required role
4. Token version matches current version

---

## 🛡️ Input Validation & Sanitization

### SQL Injection Protection

**All queries use PDO prepared statements:**
```php
// ✅ Safe - uses parameterized queries
DB::table('users')
    ->where('email', $request->input('email'))
    ->first();

// ❌ Never do this - vulnerable to SQL injection
DB::raw("SELECT * FROM users WHERE email = '" . $input . "'");
```

**Protected Components:**
- QueryBuilder (all methods)
- Model CRUD operations
- Schema Builder migrations
- Raw query execution (with bindings)

### XSS Prevention

**Automatic output encoding:**
```php
// Response::json() automatically escapes HTML entities
return Response::json([
    'message' => $userInput // Automatically escaped
]);
```

**For HTML responses, use htmlspecialchars:**
```php
echo htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8');
```

### Mass Assignment Protection

**Models require explicit `$fillable` declaration:**
```php
class User extends Model {
    // Only these fields can be mass-assigned
    protected array $fillable = ['name', 'email'];
    
    // These are protected automatically:
    // - password
    // - role
    // - is_admin
}

// ❌ Will trigger warning and block unauthorized fields
User::create($request->all());

// ✅ Explicitly allow only safe fields
User::create($request->only(['name', 'email']));
```

**Runtime Warning:**
If `$fillable` is empty, framework triggers `E_USER_WARNING`:
```
Mass assignment protection: $fillable is empty on User model. 
No fields will be mass-assigned. Define $fillable array.
```

---

## 🔒 CSRF Protection

### Enable CSRF Middleware

```php
// Add to sensitive routes
Route::post('/api/data', [Controller::class, 'store'])
    ->middleware([CsrfMiddleware::class]);
```

### Generate CSRF Token

```php
// In HTML forms
echo CsrfMiddleware::field();
// Output: <input type="hidden" name="_token" value="abc123...">

// In JavaScript meta tag
echo CsrfMiddleware::metaTag();
// Output: <meta name="csrf-token" content="abc123...">
```

### Verify Token in Requests

```javascript
// Include token in AJAX requests
fetch('/api/data', {
    method: 'POST',
    headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify(data)
});
```

---

## ⏱️ Rate Limiting

### Protect Sensitive Endpoints

```php
// Login endpoint - 5 attempts per minute
Route::post('/auth/login', [AuthController::class, 'login'])
    ->throttle(5, 1);

// Registration - 3 attempts per hour
Route::post('/auth/register', [AuthController::class, 'register'])
    ->throttle(3, 60);

// API endpoints - 60 requests per minute
Route::get('/api/users', [UserController::class, 'index'])
    ->throttle(60, 1);
```

### Rate Limit Headers

Every throttled response includes:
```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1635724800
Retry-After: 120  # When limit exceeded
```

### Monitor Rate Limits

```bash
# View active rate limits
php siro rate:status

# Output:
# +---------------------+-------+------+---------+
# | Key                 | Count | TTL  | Status  |
# +---------------------+-------+------+---------+
# | 30ff2cff9fb616d9... | 45    | 30s  | OK      |
# | 4840fcb0d11385...   | 61    | 15s  | BLOCKED |
# +---------------------+-------+------+---------+
```

---

## 🔑 Credential Handling

### Password Hashing

**Use bcrypt automatically:**
```php
// Hash password
$hashedPassword = Hash::make('secret123');

// Verify password
if (Hash::check('secret123', $hashedPassword)) {
    // Password matches
}
```

**Algorithm:** Bcrypt with cost factor 12 (configurable)

### Credential Sanitization in Logs

**Sensitive data automatically redacted:**
```php
// Request body logged as:
{"email":"test@test.com","password":"[REDACTED]"}

// Not:
{"email":"test@test.com","password":"secret123"}
```

**Redacted Fields:**
- `password`
- `password_confirmation`
- `token`
- `access_token`
- `refresh_token`
- `secret`
- `api_key`

### Environment Variable Protection

**Never commit `.env` file:**
```gitignore
# .gitignore
.env
.env.*
!.env.example
```

**Auto-generate secure secrets:**
```bash
# Generate APP_KEY
php siro key:generate

# Generates: APP_KEY=base64:random-32-byte-key
```

---

## 🌐 CORS Configuration

### Configure Allowed Origins

```php
// config/cors.php
return [
    'allowed_origins' => ['https://example.com'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
    'allowed_headers' => ['Content-Type', 'Authorization'],
    'exposed_headers' => ['X-Request-Id', 'X-RateLimit-Limit'],
    'max_age' => 3600,
    'supports_credentials' => true,
];
```

### Test CORS Configuration

```bash
# Automated CORS validation
php siro api:test GET /api/users --cors

# Output:
# [1/3] OPTIONS preflight request... ✓
# [2/3] Request with Origin header... ✓
# [3/3] Request without Origin... ✓
# CORS configuration is valid!
```

---

## 📁 File Upload Security

### Validate Uploaded Files

```php
$file = $request->file('avatar');

// Check file type
if (!$file->isValid()) {
    throw new \Exception('Invalid file upload');
}

// Restrict file types
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
if (!in_array($file->getMimeType(), $allowedTypes)) {
    throw new \Exception('File type not allowed');
}

// Limit file size (5MB max)
if ($file->getSize() > 5 * 1024 * 1024) {
    throw new \Exception('File too large');
}

// Store securely
$path = $file->store('avatars', 'public');
```

### Prevent Path Traversal

**Framework sanitizes filenames automatically:**
```php
// Malicious filename: "../../../etc/passwd"
// Sanitized to: "etc_passwd" or rejected
```

### Serve Files Safely

```php
// Create symbolic link
php siro storage:link

// Access files via public URL
// http://yoursite.com/storage/avatars/photo.jpg
```

---

## 🔍 Security Headers

### Automatic Security Headers

Every response includes:
```http
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

### Customize Headers

```php
use Siro\Core\Response;

return Response::json($data)
    ->header('X-Custom-Header', 'value')
    ->withHeaders([
        'X-Another-Header' => 'another-value',
    ]);
```

---

## 🗄️ Database Security

### Multi-Database Connection Security

**Separate credentials for read/write:**
```env
# Write connection (restricted access)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=myapp_production
DB_USERNAME=app_writer
DB_PASSWORD=strong-password-here

# Read replica (read-only user)
DB_READ_HOST=replica.example.com
DB_READ_USERNAME=app_reader
DB_READ_PASSWORD=another-strong-password
```

### Slow Query Detection

**Detect potential SQL injection attempts:**
```env
DB_SLOW_QUERY_THRESHOLD=100  # Log queries > 100ms
```

**Logged to `storage/logs/error.log`:**
```
Slow query (150.25ms): SELECT * FROM users WHERE email = :email
Bindings: {"email":"test@example.com"}
```

---

## 🚨 Error Handling

### Production Error Configuration

```env
APP_DEBUG=false  # Never enable in production
```

**When `APP_DEBUG=false`:**
- Generic error messages shown to users
- Detailed errors logged internally
- Stack traces hidden from response
- Database credentials never exposed

### Custom Error Pages

```php
// Handle specific HTTP errors
if ($e instanceof NotFoundHttpException) {
    return Response::json([
        'error' => 'Resource not found'
    ], 404);
}

if ($e instanceof ValidationException) {
    return Response::json([
        'errors' => $e->errors()
    ], 422);
}
```

---

## 🔐 Encryption

### AES-256 Encryption

```php
use Siro\Core\Encrypter;

// Encrypt sensitive data
$encrypted = Encrypter::encrypt($creditCardNumber);

// Decrypt when needed
$decrypted = Encrypter::decrypt($encrypted);
```

**Features:**
- AES-256-CBC encryption
- HMAC integrity verification
- Tamper-proof payload
- Auto key resolution from `APP_KEY`

### When to Encrypt

**Always encrypt:**
- Credit card numbers
- Social security numbers
- Personal identification data
- API keys stored in database
- Sensitive user preferences

**Don't encrypt:**
- Passwords (use Hash::make instead)
- Public data
- Data needed for search/filtering

---

## 🛠️ Security Checklist

### Pre-Deployment Checklist

```bash
# 1. Validate environment
php siro env:check

# Checks:
# ✅ .env file exists
# ✅ Required variables set
# ✅ JWT_SECRET strength (min 32 chars)
# ✅ APP_DEBUG is false
# ✅ PHP extensions loaded
# ✅ Storage directories writable

# 2. Run security tests
php vendor/bin/phpunit --testsuite=Security

# 3. Check rate limiting
php siro rate:status

# 4. Verify HTTPS
curl -I https://yourdomain.com/api/health
# Should return: Strict-Transport-Security header

# 5. Test CORS
php siro api:test GET /api/users --cors
```

### Production Hardening

1. **Disable debug mode:**
   ```env
   APP_DEBUG=false
   ```

2. **Use strong secrets:**
   ```bash
   php siro key:generate
   ```

3. **Enable maintenance mode during updates:**
   ```bash
   php siro down --allow=YOUR_IP
   # Deploy code
   php siro up
   ```

4. **Set proper file permissions:**
   ```bash
   chmod 755 storage/
   chmod 644 storage/logs/*.log
   ```

5. **Configure firewall:**
   - Allow only ports 80, 443
   - Restrict database access to app server IP
   - Block direct access to `.env` file

---

## 🚨 Incident Response

### If Breach Suspected

1. **Rotate all tokens:**
   ```sql
   UPDATE users SET token_version = token_version + 1;
   ```

2. **Change JWT secret:**
   ```bash
   php siro key:generate
   ```

3. **Review trace logs:**
   ```bash
   php siro log:trace --status=500
   php siro log:export --days=7 --format=json --output=incident.json
   ```

4. **Check failed jobs:**
   ```bash
   php siro queue:status
   ```

5. **Audit user actions:**
   ```sql
   SELECT * FROM audit_logs 
   WHERE created_at > NOW() - INTERVAL 24 HOUR;
   ```

---

## 📚 Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)
- [JWT Best Practices](https://tools.ietf.org/html/rfc8725)
- [CORS Specification](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS)

---

## 📞 Reporting Security Issues

If you discover a security vulnerability, please report it responsibly:

**Email:** security@sirosoft.com  
**PGP Key:** Available on request  
**Response Time:** Within 48 hours

**Do NOT:**
- Open public GitHub issues
- Post on social media
- Exploit the vulnerability

**DO:**
- Send detailed report via email
- Include steps to reproduce
- Provide suggested fix if possible

We appreciate responsible disclosure and will credit researchers who help keep SiroPHP secure.
