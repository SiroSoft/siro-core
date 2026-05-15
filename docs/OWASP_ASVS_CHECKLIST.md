# OWASP ASVS Level 2 Checklist — Siro Core

## V2: Authentication (14 checks)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 2.1 | Password minimum length 8 | ✅ | Enforced in Auth |
| 2.2 | No password composition rules | ✅ | No arbitrary rules |
| 2.3 | Password change requires current password | ✅ | |
| 2.4 | Credential recovery secure | ✅ | Reset token hashed |
| 2.5 | Password strength meter | ⚠️ | Application layer |
| 2.6 | Protect against credential stuffing | ✅ | Rate limiting |
| 2.7 | Session timeout configured | ✅ | Idle timeout in Session |
| 2.8 | Session regeneration on login | ✅ | Session::regenerate() |
| 2.9 | Anti-CSRF tokens | ✅ | CsrfMiddleware |
| 2.10 | Anti-automation controls | ✅ | ThrottleMiddleware |
| 2.11 | JWT algorithm pinned | ✅ | Auth\JWT.php |
| 2.12 | JWT expiration validated | ✅ | |
| 2.13 | JWT blacklist (JTI) | ✅ | |
| 2.14 | API key auth supported | ✅ | Auth\ApiKey.php |

## V3: Session Management (6 checks)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 3.1 | Session ID random | ✅ | random_bytes(32) |
| 3.2 | Session ID length >= 64 bits | ✅ | 256 bits |
| 3.3 | Cookie secure flag | ✅ | always true |
| 3.4 | Cookie HttpOnly flag | ✅ | always true |
| 3.5 | Cookie SameSite | ✅ | Lax |
| 3.6 | Session destroy on logout | ✅ | Session::destroy() |

## V4: Access Control (5 checks)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 4.1 | Consistent authorization across app | ✅ | AuthMiddleware |
| 4.2 | Principle of least privilege | ✅ | Per-route middleware |
| 4.3 | Protected against IDOR | ⚠️ | Application layer |
| 4.4 | API authorization per endpoint | ✅ | Route middleware |
| 4.5 | RBAC / permission model | ✅ | Role-based in Auth |

## V5: Input Validation (8 checks)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 5.1 | Input validation on all inputs | ✅ | Validator |
| 5.2 | SQL injection prevention | ✅ | Prepared statements |
| 5.3 | No eval() or dangerous functions | ✅ | Banned in SAST linter |
| 5.4 | File upload validation | ✅ | MIME + extension check |
| 5.5 | Path traversal prevention | ✅ | realpath() boundary |
| 5.6 | SSRF prevention | ⚠️ | Http client basic |
| 5.7 | OpenAPI spec validation | ✅ | Contract CI |
| 5.8 | Content-Type validation | ✅ | JsonMiddleware |

## V6: Output Encoding (4 checks)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 6.1 | XSS prevention via encoding | ✅ | htmlspecialchars |
| 6.2 | JSON output safe encoding | ✅ | JSON_UNESCAPED_UNICODE |
| 6.3 | Content-Type headers correct | ✅ | application/json |
| 6.4 | Server info disclosure prevented | ⚠️ | expose_php not disabled |

## V7: Cryptography (6 checks)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 7.1 | Strong random generation | ✅ | random_bytes() |
| 7.2 | Constant-time comparison | ✅ | hash_equals() |
| 7.3 | AES-256 encryption | ✅ | Encrypter |
| 7.4 | HMAC integrity verification | ✅ | Encrypt-then-MAC |
| 7.5 | Key derivation (HKDF) | ✅ | Encrypter::key() |
| 7.6 | Bcrypt cost >= 10 | ✅ | cost 12 |

## V8: Error Handling (4 checks)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 8.1 | No stack trace in production | ✅ | |
| 8.2 | Consistent error format | ✅ | Response::error() |
| 8.3 | Log errors with trace ID | ✅ | Logger::error() |
| 8.4 | RFC 7807 error format | ✅ | Response::problem() |

## V11: Business Logic (3 checks)

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 11.1 | Rate limiting per endpoint | ✅ | ThrottleMiddleware |
| 11.2 | Audit logging for sensitive ops | ✅ | AuditMiddleware |
| 11.3 | Idempotency for non-idempotent APIs | ✅ | IdempotencyMiddleware |

---

**Total: 50 checks — 45 ✅ PASS, 5 ⚠️ PARTIAL**
**Compliance: 90% (target ASVS L2 = 85%)**
