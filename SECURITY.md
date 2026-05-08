# Security Policy

## Security Audit Summary (v0.16.1)

Last audited: 2026-05-08

### ✅ Verified Secure Components

| Component | Status | Notes |
|-----------|--------|-------|
| **JWT Authentication** | ✅ Secure | HS256/RS256 support, JTI uniqueness, iat/exp validation, secret strength enforcement |
| **Password Hashing** | ✅ Secure | Uses `password_hash()` with bcrypt, no timing attacks |
| **CSRF Protection** | ✅ Secure | `hash_equals()` for timing-safe comparison, proper token regeneration |
| **SQL Injection** | ✅ Secure | PDO prepared statements throughout, parameterized queries |
| **XSS Prevention** | ✅ Secure | Output encoding in View.php, htmlspecialchars in CsrfMiddleware |
| **Mass Assignment** | ✅ Secure | `fillable` array required, guarded by default |
| **Rate Limiting** | ✅ Secure | Per-route throttling with atomic lock files |
| **File Upload** | ✅ Secure | MIME type validation, path traversal prevention |
| **Session Management** | ✅ Secure | Secure session ID regeneration, HTTP-only cookies |
| **HTTPS Enforcement** | ✅ Secure | Strict transport security headers in CorsMiddleware |
| **Input Sanitization** | ✅ Secure | Null byte removal, URL-encoded null byte stripping |
| **IP Spoofing Prevention** | ✅ Secure | Trusted proxy validation, X-Forwarded-For parsing |
| **HMAC Integrity** | ✅ Secure | SHA-256 HMAC verification in Encrypter |
| **Error Handling** | ✅ Secure | No stack traces leaked, all errors logged |

### 🔐 Security Features

1. **JWT Tokens**
   - Algorithm verification (rejects algorithm confusion attacks)
   - Secret strength requirement (min 32 chars, rejects placeholders)
   - Token version checking (prevents replay of old tokens)
   - JTI (JWT ID) for token uniqueness

2. **Encryption**
   - AES-256-CBC with random IV
   - HMAC-SHA256 integrity verification
   - Auto key resolution with fallback chain

3. **Request Validation**
   - Content-Length spoofing prevention
   - Actual body size validation
   - Maximum body size limit (2MB)

4. **Route Parameter Sanitization**
   - Null byte stripping from route params
   - URL-encoded null byte removal

5. **Path Normalization**
   - Null bytes and URL-encoded nulls stripped
   - Trailing slash normalization

### 🚨 Reporting Security Issues

If you discover a security vulnerability, please report it via:
- GitHub Issues: https://github.com/SiroSoft/siro-core/issues
- Email: security@sirosoft.com

We will respond within 48 hours and patch critical issues immediately.