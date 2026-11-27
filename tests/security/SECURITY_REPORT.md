# Siro Framework Security Penetration Test Report — v1.5.0

**Date:** 2026-11-26
**Tester:** Lane G8 — evidence (k6 + pen-test), framework v1.5.0
**Scope:** siro-core 1.5.0-dev — merged assembly preview (G1 platform + G2 OAuth2 + G3 auto-CRUD + G4 RFC 7807 + G6 throttle tier + G5 webhooks + G7 OpenAPI CI), branch `staging/g8-preview`, HEAD `5df9f6a`
**Tests Executed (Security testsuite):** 73
**Passed:** 73
**Failed:** 0
**DAST suite (separate):** 157 tests / 166 assertions — OK
**Critical / High open:** 0

---

## Executive Summary

The merged v1.5.0 tree keeps the strong security posture of the 2026-05-13 baseline (42 tests, all passing) and adds 31 new tests covering the new 1.5.0 attack surface: OAuth2 scopes and tokens (16 tests, Lane G2), plus JSON-API hardening — JWT expiry/tampering, throttle bypass, auto-CRUD authorization, JSON XSS, webhook signature/replay (15 tests, Lane G8). **No exploitable vulnerability was found in the new surface. 0 critical / 0 high findings open.**

One design boundary is documented in §4 (not a vulnerability): auto-CRUD authorizes at **ability level** (`{table}.{verb}` via Gate, deny-by-default, checked before the row is loaded). It does **not** enforce row-level ownership — two users holding the same ability can read each other's rows. Applications needing row ownership must scope it themselves.

---

## 1. Baseline 2026-05-13 (preserved)

The previous report (42 tests, 42 passed, 0 failed; tester "QC Engineer #2") covered OWASP Top 10 (SQL injection, XSS, CSRF, auth bypass, path traversal, LFI, RCE, deserialization, command injection, XXE), JWT security (8 tests incl. algorithm confusion, none-algorithm, signature stripping, expiration bypass), cryptographic primitives (AES-256-CBC Encrypt-then-MAC, HKDF-like key derivation, timing-safe `hash_equals`), timing attacks, input validation (null byte), and session security (256-bit IDs, regeneration, cookie flags).

All 42 baseline tests still pass unchanged on the merged v1.5.0 tree (`tests/security/PenetrationTest.php`). The full 2026-05-13 report text is preserved in git history (file `tests/security/SECURITY_REPORT.md` at commit `79a0fe1` and earlier). Its recommendations (SameSite=Strict for non-OAuth session cookies, CSP header, JWT rotation grace period, rate-limiting JWT decode failures, libsodium migration) are carried forward unchanged in §5 — none was in the v1.5.0 scope.

---

## 2. New v1.5.0 tests — OAuth2 (Lane G2, 16 tests)

`tests/security/OAuth2PenTest.php` — 16 tests / 27 assertions, all PASS.

| Test | Result | Severity |
|------|--------|----------|
| Scope enforcement (missing scope → 403 `insufficient_scope`; granted scope → 200) | **PASS** | High |
| Garbage / revoked token → 401 | **PASS** | High |
| Token expiry enforced | **PASS** | High |
| Client-credentials grant cannot escalate scope | **PASS** | High |
| Refresh token rotation / revocation | **PASS** | High |
| `isAvailable()=false` without `league/oauth2-server` (suggest-only) — fail-closed 401, no fatal | **PASS** | High |

**Finding:** OAuth2 is an optional dependency (`composer suggest`, not `require`). Without the library the middleware fails closed (401) and `create()` throws a clear RuntimeException. Scope is checked per route via `OAuthScopeMiddleware` with the core JWT fallback. No scope-escalation or token-forgery vector found.

## 3. New v1.5.0 tests — JSON API hardening (Lane G8, 15 tests)

`tests/security/PenTest150Test.php` — 15 tests / 34 assertions, all PASS.

| # | Test | Result | Severity |
|---|------|--------|----------|
| 1 | JWT expired → decode throws `Token expired.` | **PASS** | High |
| 2 | JWT tampered signature → throws `Invalid token signature.` | **PASS** | Critical |
| 3 | OAuth2 missing scope → 403 `insufficient_scope` | **PASS** | High |
| 4 | OAuth2 granted scope → 200 | **PASS** | High |
| 5 | Garbage token → 401 | **PASS** | High |
| 6 | Throttle flat limit 5 → 6th request 429 | **PASS** | Medium |
| 7 | `X-Forwarded-For` spoof does **not** bypass throttle (`Request::ip()` returns the constructor `clientIp`, never reads XFF) | **PASS** | High |
| 8 | Different client IP gets its own throttle bucket | **PASS** | Medium |
| 9 | Auto-CRUD deny-by-default (no Gate → `AuthorizationException`, app maps to 403) | **PASS** | High |
| 10 | No existence oracle — unauthorized user probing a non-existent id → 403, not 404 (auth runs before row load) | **PASS** | High |
| 11 | Per-user ability — user 1 allowed / user 2 denied | **PASS** | High |
| 12 | Update without ability → 403 | **PASS** | High |
| 13 | JSON XSS — `Response::json` uses `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT`; `<script>` becomes `\u003Cscript\u003E`, round-trips correctly | **PASS** | High |
| 14 | Webhook wrong secret / tampered payload / replay outside 300 s window / malformed header → `false`; inside window → `true` (`WebhookSigner::sign`/`verify`, `t={ts},v1={hmac-sha256(ts.payload)}`, tolerance 300 s) | **PASS** | High |

**Findings:**
- JWT: server-configured algorithm only; expiry and signature verified before any claim is trusted.
- Throttle: keyed on the framework `clientIp` (set by the server from the transport, not from `X-Forwarded-For`), so a spoofed header cannot move the request into another bucket or reset a limit. Applications behind a trusted proxy must set `clientIp` from the proxy's validated header themselves; the framework deliberately does not trust XFF by default.
- Auto-CRUD: authorization happens **before** the row is loaded, so an unauthorized probe cannot distinguish "missing row" from "forbidden row" (both 403). See §4 for the row-ownership boundary.
- JSON encoding: all four hex flags are set, so a JSON body containing `<script>` cannot break out of an HTML context if the payload is ever embedded in a page.
- Webhooks: HMAC-SHA256 over `timestamp.payload`, 300 s replay tolerance, strict `t=…,v1=…` header format.

## 4. Documented design boundary — row-level ownership (NOT a vulnerability)

Auto-CRUD (`ApiQueryFilter` / auto-CRUD routes, Lane G3) authorizes at **ability level**: a request is allowed if the authenticated user holds `{table}.{verb}` (e.g. `products.read`) in the Gate; otherwise it is denied with 403 before any row is loaded. It does **not** check row-level ownership: two users who both hold `products.read` can read each other's rows.

This is by design for a framework-level generic CRUD layer (the framework cannot know an application's ownership model). Applications that need row ownership must scope it themselves — custom middleware, `Gate::before`, or a scoped query. QC2/assembly must ensure this is stated in `docs/SECURITY.md` (or the auto-CRUD docs) so app developers do not assume row isolation.

## 5. DAST suite

`tests/dast/DastSecurityTest.php` — 157 tests / 166 assertions, **OK** on the merged tree. Data-provider coverage: SQL injection paths, XSS paths, path traversal, HTTP methods, malformed headers.

---

## Overall Risk Assessment

| Category | Risk Level |
|----------|------------|
| SQL Injection | None |
| Cross-Site Scripting | None |
| CSRF | None |
| JWT / OAuth2 Attacks | None |
| Cryptographic Weaknesses | None |
| Path Traversal | None |
| Command Injection | None |
| Session Hijacking | None |
| Throttle Bypass (XFF spoof) | None |
| IDOR (ability level) | None — see §4 boundary |
| Webhook Forgery / Replay | None |
| **Overall** | **Low** |

---

## Recommendations

### 1. High Priority
None identified.

### 2. Medium Priority (carried from 2026-05-13, unchanged in v1.5.0)
- **Add `SameSite=Strict` for session cookies** in non-OAuth contexts (currently `Lax`).
- **Consider adding a `Content-Security-Policy` header** with `script-src 'self'` as defense-in-depth.
- **Document the auto-CRUD row-ownership boundary (§4)** in `docs/SECURITY.md` / auto-CRUD docs before the v1.5.0 tag, so apps do not assume row isolation.

### 3. Low Priority / Informational (carried from 2026-05-13)
- JWT key rotation grace period: enforce a short window (currently configurable; default should enforce 5-minute max).
- Rate-limit JWT decode failures to mitigate brute-force against weak secrets (minimum 32-byte secret already enforced).
- Consider migrating to `sodium_crypto_aead_xchacha20poly1305` (libsodium) for authenticated encryption instead of AES-256-CBC + HMAC.

---

## Test Coverage Summary

| Attack Vector | Tests | Passed |
|--------------|-------|--------|
| SQL Injection (baseline + DAST) | 4 + DAST | 4 + DAST |
| Cross-Site Scripting (baseline + JSON + DAST) | 3 + 1 | 4 |
| CSRF | 3 | 3 |
| JWT / OAuth2 (baseline 8 + G2 16 + G8 5) | 29 | 29 |
| Path Traversal (baseline + DAST) | 2 + DAST | 2 + DAST |
| Cryptographic | 5 | 5 |
| Timing Attacks | 3 | 3 |
| Input Validation | 2 | 2 |
| Command Injection | 2 | 2 |
| XXE | 1 | 1 |
| Authentication | 2 | 2 |
| Session Security | 3 | 3 |
| Deserialization / RCE | 2 | 2 |
| Throttle / XFF spoof | 3 | 3 |
| Auto-CRUD authorization / IDOR | 4 | 4 |
| Webhook signature / replay | 1 | 1 |
| **Security testsuite total** | **73** | **73** |
| **DAST suite (separate)** | **157** | **157** |

---

*Report supersedes the 2026-05-13 report (42 tests). Baseline text preserved in git history at `tests/security/SECURITY_REPORT.md` (commit `79a0fe1` and earlier). Generated by Lane G8, framework v1.5.0, 2026-11-26.*
