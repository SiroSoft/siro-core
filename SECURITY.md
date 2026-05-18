# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |
| 0.27.x  | :white_check_mark: |
| 0.26.x  | :white_check_mark: |
| < 0.26  | :x:                |

---

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please report it responsibly.

### How to Report

**Email:** security@sirosoft.com  
**PGP Key:** Available on request  
**Response Time:** Within 48 hours

### What to Include

1. **Description** of the vulnerability
2. **Steps to reproduce** the issue
3. **Potential impact** assessment
4. **Suggested fix** (if you have one)
5. **Your contact information** for follow-up

### What NOT to Do

- ❌ Do NOT open public GitHub issues
- ❌ Do NOT post on social media
- ❌ Do NOT exploit the vulnerability beyond testing
- ❌ Do NOT disclose before coordinated release

---

## Disclosure Process

1. **Report received** - We acknowledge within 48 hours
2. **Investigation** - We verify and assess severity
3. **Fix development** - We create patch privately
4. **Testing** - We test fix thoroughly
5. **Coordinated disclosure** - We agree on release date
6. **Public announcement** - We publish advisory
7. **Patch release** - We release fixed version

### Timeline

- **Acknowledgment**: Within 48 hours
- **Initial assessment**: Within 1 week
- **Fix development**: 1-4 weeks (depends on complexity)
- **Public disclosure**: After patch available
- **Total process**: Typically 2-6 weeks

---

## Severity Levels

### Critical
- Remote code execution
- SQL injection with data exfiltration
- Authentication bypass
- Complete system compromise

**Response**: Immediate action, emergency patch within 7 days

### High
- Cross-site scripting (XSS) with session hijacking
- Privilege escalation
- Sensitive data exposure
- CSRF with critical actions

**Response**: Priority fix in next release (within 2 weeks)

### Medium
- Information disclosure (non-sensitive)
- Rate limiting bypass
- Session fixation
- Open redirect

**Response**: Fix in scheduled release (within 1 month)

### Low
- Minor information leakage
- Missing security headers
- Verbose error messages
- Non-critical configuration issues

**Response**: Fix in future release (within 3 months)

---

## Bug Bounty Program

Currently, we do not offer monetary rewards for bug reports. However, we provide:

- ✅ Public recognition in security advisories
- ✅ Hall of Fame listing
- ✅ swag for significant findings
- ✅ Professional reference upon request

---

## Security Advisories

All security advisories are published at:
https://github.com/SiroSoft/siro-core/security/advisories

### Recent Advisories

**2026-05-01**: File Download Security Fix
- **Severity**: High
- **Affected**: v0.12.0 - v0.12.9
- **Fixed in**: v0.13.0
- **CVE**: CVE-2026-XXXX

**2026-04-15**: JWT JTI Consistency Issue
- **Severity**: Medium
- **Affected**: v0.10.0 - v0.12.9
- **Fixed in**: v0.13.0
- **CVE**: CVE-2026-YYYY

---

## Best Practices for Users

### Keep Updated

```bash
# Always use latest stable version
composer update sirosoft/core

# Check for security updates
composer audit
```

### Secure Configuration

```env
# Use strong secrets
JWT_SECRET=minimum-32-character-random-string
APP_KEY=base64:generated-by-php-siro-key-generate

# Disable debug in production
APP_DEBUG=false

# Set proper environment
APP_ENV=production
```

### Enable Security Features

```php
// Add CSRF protection
Route::post('/api/data', [Controller::class, 'store'])
    ->middleware([CsrfMiddleware::class]);

// Enable rate limiting
Route::post('/auth/login', [AuthController::class, 'login'])
    ->throttle(5, 1);

// Use HTTPS only
// Configure in web server (Nginx/Apache)
```

### Monitor for Issues

```bash
# Check slow requests
php siro slow

# View error logs
tail -f storage/logs/main/error.log

# Monitor trace logs
php siro log:trace --status=500
```

---

## Security Features Overview

### Built-in Protections

✅ **SQL Injection Prevention** - PDO prepared statements  
✅ **XSS Protection** - Automatic output encoding  
✅ **CSRF Protection** - Token-based middleware  
✅ **Rate Limiting** - Per-route throttling  
✅ **Mass Assignment Protection** - Explicit $fillable required  
✅ **Password Hashing** - Bcrypt with configurable cost  
✅ **Credential Sanitization** - Auto-redact in logs  
✅ **JWT Security** - Token versioning and rotation  
✅ **Security Headers** - Auto-added to responses  
✅ **File Upload Validation** - Type and size checks  

### Additional Recommendations

1. **Use HTTPS** - Always in production
2. **Enable WAF** - Web Application Firewall
3. **Regular backups** - Database and files
4. **Monitor logs** - Set up alerts
5. **Update dependencies** - Regular composer updates
6. **Restrict file permissions** - Principle of least privilege
7. **Use environment variables** - Never hardcode secrets
8. **Implement audit logging** - Track sensitive actions

---

## Contact

**Security Team:** security@sirosoft.com  
**General Inquiries:** support@sirosoft.com  
**GitHub:** https://github.com/SiroSoft/siro-core

For urgent security issues, email is preferred over public channels.

---

## Acknowledgments

We thank all security researchers who responsibly disclose vulnerabilities:

- [Researcher Name] - SQL injection discovery (2026-04)
- [Researcher Name] - XSS vulnerability report (2026-03)
- [Researcher Name] - Authentication bypass finding (2026-02)

Your contributions help keep SiroPHP secure for everyone!

---

*Last updated: May 11, 2026*
