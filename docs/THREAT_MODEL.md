# Threat Model — SiroCore Framework

## Methodology
- **STRIDE** per component (Spoofing, Tampering, Repudiation, Information Disclosure, Denial of Service, Elevation of Privilege)
- **DFD** (Data Flow Diagram) context: External Entities → System Processes → Data Stores
- **Risk rating**: Critical / High / Medium / Low
- All analysis is based on actual source code at specific file paths and line numbers in `siro-core/`

## Assets
| Asset | Location / Storage | Sensitivity |
|---|---|---|
| JWT tokens | Memory (request lifecycle) + cache (blacklist) | Critical |
| API keys (plaintext) | Transmitted in header, hashed in DB (sha256 + bcrypt) | Critical |
| User credentials (passwords) | bcrypt-hashed in DB | Critical |
| Session cookies | File system or Redis, HTTP-only | High |
| Cache data | JSON files (`storage/cache/`) or Redis | Medium |
| Queue jobs | Database (`jobs` table) | Medium |
| Mail credentials | `.env` (MAIL_USERNAME, MAIL_PASSWORD) | High |
| Database credentials | `.env` / config | Critical |
| Encryption keys | `.env` (APP_KEY, JWT_SECRET) | Critical |
| Config secrets | `.env`, cached `config.php` | Critical |
| CSRF tokens | Session store or cookie | High |
| Rate limit data | Redis or filesystem | Low |

## Trust Boundaries
- **External/Internet** ⇢ **Middleware Pipeline** ⇢ **Application Code** ⇢ **Database/Storage**
- **Module Boundaries:**
  - Client ↔ Middleware Pipeline (Router: `Router.php:131-200`)
  - Middleware ↔ Controller (Handler dispatch: `Router.php:177-199`)
  - Controller ↔ Database (QueryBuilder: `DB/QueryBuilder.php`)
  - Application ↔ Cache (File/Redis drivers: `Cache/Drivers/`)
  - Application ↔ Queue (Database: `Queue.php`)

---

## Per-Module Threat Analysis

### 1. Router + Middleware Pipeline

**Data Flow**: HTTP Request → `Router::dispatch()` → Route matching (`RouteMatcher::match()`) → Middleware pipeline (reverse-ordered) → Handler execution → Response

**File locations**: `Router.php` (581 lines), `RouteMatcher.php` (280 lines), `Route.php`, `MiddlewareInterface.php`

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **S** | Middleware bypass via OPTIONS handler | **High** | `Router.php:136-138`: OPTIONS requests bypass all route middleware. Only `groupMiddleware` is applied (`Router.php:568`). An attacker could probe endpoints with OPTIONS to avoid auth checks. |
| **T** | Route cache poisoning | **Medium** | `Router.php:228-245`: `loadFromCache()` reads a JSON file with a `<?php exit; ?>` prefix but does not validate cryptographic integrity. An attacker with filesystem write could inject malicious route data. |
| **T** | Middleware parameter injection via string parsing | **Medium** | `Router.php:493-499`: Middleware parameters are parsed from colon-delimited strings. `explode(',', ...)` on user-controlled middleware names could cause parameter confusion. |
| **R** | No per-route audit trail | **Low** | The router does not enforce audit logging. Relies on explicit `AuditMiddleware` registration. Routes without audit middleware have no repudiation protection. |
| **I** | Route enumeration via pathExists() | **Low** | `RouteMatcher.php:113-136`: `pathExists()` reveals whether a path matches any route, including those behind auth middleware. Used in OPTIONS handling. No rate limiting on this check. |
| **D** | Middleware pipeline resource exhaustion | **Medium** | `Router.php:181-187`: Middleware runs sequentially. A slow middleware (e.g., DB lookup) blocks the pipeline. No middleware execution timeout. |
| **E** | Closure handler privilege escalation | **Medium** | `Router.php:386-393`: Closure handlers run with full application context. If a Closure is cached in route cache (`saveToCache` filters Closures at `Router.php:261-267`), but fresh registrations accept any callable. |

**Existing Mitigations**:
- `RouteMatcher::match()`: In-memory match cache prevents repeated matching (`RouteMatcher.php:51-53`)
- Static routes are O(1) hash lookup, dynamic routes use linear scan with segment count pre-filter
- `setRouteMiddleware()`: Proper merging with existing middleware (`Router.php:314-344`)
- Route cache uses `<?php exit; ?>` prefix to prevent direct PHP execution (`Router.php:271`)

**Gaps**:
- No middleware timeout mechanism
- No route-access logging by default
- Route cache lacks integrity verification (no HMAC)
- OPTIONS handler skips route-specific middleware

**Recommendations**:
1. Apply `groupMiddleware` plus route middleware to OPTIONS requests, not just group middleware
2. Add HMAC signature to the route cache file for integrity verification
3. Implement middleware execution timeout using `set_time_limit()` or a timer

### 2. Auth (JWT + AuthGuard + AuthMiddleware)

**Data Flow**: Request → `AuthMiddleware::handle()` → Extract Bearer token → `JWT::decode()` → Verify signature + claims → `ModelUserProvider::retrieveById()` → User object → Request attribute

**File locations**: `Auth/JWT.php` (367 lines), `Auth/AuthGuard.php` (129 lines), `Middleware/AuthMiddleware.php` (147 lines), `Auth/ModelUserProvider.php`

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **S** | Algorithm confusion (alg none attack) | **Low** | `JWT.php:144-148`: Algorithm is pinned server-side from `JWT_ALGORITHM` env. The token's `alg` header is read but the server's configured algorithm is always used for verification. **Mitigated.** |
| **S** | Weak secret / placeholder detection | **Low** | `JWT.php:267-276`: Rejects secrets < 32 chars and placeholder strings ("change_this", "please_set", "your_secret"). `App.php:271-281`: Boot-time validation also enforces this. **Mitigated.** |
| **S** | Token theft via MITM (missing HSTS enforcement) | **High** | The framework provides `CspMiddleware` but does not set `Strict-Transport-Security` by default. If the application is served over HTTP, JWTs can be intercepted. |
| **T** | Token forgery via RS256 key confusion | **Medium** | `JWT.php:233-254`: RS256 verification uses `openssl_verify()`. No validation that the public key matches a known certificate. An attacker with a self-signed cert could forge tokens if `JWT_PUBLIC_KEY` is misconfigured. |
| **T** | Token version rollback attack | **Medium** | `AuthMiddleware.php:96-101`: Compares `token_version` claim to DB. If an old `token_version` is leaked, a replay could succeed until the user re-logs. The mitigation is DB-side version tracking. |
| **R** | Authentication failure not logged in security channel | **Medium** | `AuthMiddleware.php:138-139`: Auth failures go to `Logger::error()` but are not written to the security audit log (no `Logger::security()` call). |
| **I** | Token information disclosure (sub, ver in claim) | **Low** | JWT payload contains `sub` (user ID), `ver` (token version). These are base64-encoded, not encrypted. If the JWT is not used with TLS, user enumeration is possible. |
| **D** | Repeated failed auth (no built-in rate limiting on AuthMiddleware) | **High** | `AuthMiddleware.php` does not natively integrate rate limiting. The application must explicitly add `ThrottleMiddleware` on auth routes. If forgotten, brute force is possible. |
| **E** | Role bypass via type confusion | **Medium** | `AuthMiddleware.php:123-130`: Role check is case-insensitive (`strtolower`). However, if the `role` attribute is missing from the user data, it defaults to `'user'` at line 106. No admin-by-default risk. |

**Existing Mitigations**:
- Algorithm pinning: `JWT.php:144-148` — never trusts token's `alg` header
- JTI blacklist: `JWT.php:353-366` — supports immediate token revocation, dual storage (memory + cache)
- Key rotation: `JWT.php:292-298` — `rotateKey()` increments version, `verifyHs256WithRotation()` checks current + previous secret
- Claim validation: `JWT.php:159-183` — validates `exp`, `iat`, `nbf`, `sub`, `ver` on every decode
- Future-dated token detection: `JWT.php:165-168` — rejects `iat > now + 60s`
- `hash_equals()` for signature comparison: `JWT.php:313` — timing-safe comparison

**Gaps**:
- No built-in rate limiting on auth endpoint (must be added manually)
- Auth failures not logged to security audit channel
- No HSTS header set by default
- JWT payload is not encrypted (only signed)

**Recommendations**:
1. Integrate auth failure counting with `ThrottleMiddleware` automatically for auth routes
2. Log authentication failures to the security audit channel (`Logger::security()`)
3. Add default `Strict-Transport-Security` header when `APP_ENV=production`

### 3. API Key Authentication

**Data Flow**: Request → `ApiKeyMiddleware::handle()` → Read `X-Api-Key` header → `ApiKey::validate()` → SHA256 lookup → bcrypt verify → Scope check → Request user attribute

**File locations**: `Auth/ApiKey.php` (268 lines), `Middleware/ApiKeyMiddleware.php` (67 lines)

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **T** | Key leakage in logs/headers | **Medium** | `ApiKeyMiddleware.php:31`: Key is read from `X-Api-Key` header. Logger sanitization covers `x-api-key` in headers (`docs/SECURITY.md:309`), but the key may appear in access logs. |
| **T** | SHA256 hash leak → key recovery | **Low** | `ApiKey.php:67`: Keys are looked up by SHA256 hash, then verified with bcrypt at line 86. SHA256 alone is fast; bcrypt is the primary defense. Legacy keys (SHA256-only) are auto-migrated at line 91. |
| **I** | Timing attack on key validation | **Medium** | `ApiKey.php:84-88`: Uses `password_verify()` which is timing-safe. However, the SHA256 lookup at line 70 first filters by hash; hash comparison on the DB side is not constant-time. |
| **D** | Key enumeration via timing | **Low** | The SHA256 hash lookup (`WHERE token_hash = ?`) is a DB index seek. Timing differences between found/not-found are negligible. |
| **E** | Scope escalation via admin scope | **Medium** | `ApiKey.php:203-205`: If any key has `admin` scope, `hasScope()` returns true for all scope checks. An attacker who compromises an admin-scoped key gains full access. |
| **E** | Revocation bypass | **Medium** | `ApiKey.php:124-131`: `revoke()` deletes the key. But `validate()` checks `token_hash`, so deletion is effective. No issue with stale cache (not cached). |

**Existing Mitigations**:
- Dual hashing (SHA256 for lookup + bcrypt for verification): `ApiKey.php:38,43`
- Migration path from legacy SHA256-only keys: `ApiKey.php:89-95`
- Scope checking: `ApiKeyMiddleware.php:44-55`
- Expiration support: `ApiKey.php:97-101`
- Token generation uses `random_bytes(32)`: `ApiKey.php:264-267`

**Gaps**:
- No rate limiting on API key validation (brute force possible)
- No audit log on API key authentication
- `admin` scope is a super-scope that bypasses individual scope checks

**Recommendations**:
1. Implement account lockout after N failed API key attempts
2. Add `Logger::security()` call on API key authentication
3. Consider removing the `admin` scope bypass or making it opt-in per-resource

### 4. CSRF Protection (CsrfMiddleware)

**Data Flow**: Request → `CsrfMiddleware::handle()` → Method check (GET/HEAD/OPTIONS bypass) → Session detection → Session-based token validation OR Double-submit cookie validation → Token rotation → Next middleware

**File locations**: `Middleware/CsrfMiddleware.php` (139 lines)

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **S** | CSRF token prediction | **Low** | `CsrfMiddleware.php:82-84`: Tokens are `bin2hex(random_bytes(32))` (64 hex chars). CSPRNG provides sufficient entropy. |
| **S** | Double-submit cookie manipulation | **Medium** | `CsrfMiddleware.php:38-39`: Double-submit cookie mode reads both from cookie and header. An attacker able to set subdomain cookies could exploit this if `__host-` prefix is not used. |
| **T** | Token reuse after rotation gap | **Low** | `CsrfMiddleware.php:73-77`: Token is rotated after each successful validation. Race window is negligible. |
| **I** | Token leakage via Referer header | **Low** | Session-based token can appear in meta tag and form fields. No Referer-based leakage protection. CSP `form-action` provides mitigation. |
| **D** | Session start DoS | **Low** | `CsrfMiddleware.php:28-34`: Forces session start on every state-changing request. If session storage is slow (file/Redis), this adds latency but not DoS. |

**Existing Mitigations**:
- `hash_equals()` for token comparison (timing-safe): `CsrfMiddleware.php:46,137`
- Token rotation after validation: `CsrfMiddleware.php:73-77`
- Uses CSPRNG for token generation: `CsrfMiddleware.php:82-84`
- Dual strategy (session + double-submit cookie): `CsrfMiddleware.php:36-78`
- Safe method bypass (GET, HEAD, OPTIONS): `CsrfMiddleware.php:17`

**Gaps**:
- Double-submit cookie lacks `__Host-` prefix for cookie binding
- No SameSite cookie attribute enforcement in double-submit mode
- Token rotation is not atomic with response delivery (rotate happens before `$next`, but if `$next` fails, token is still rotated)

**Recommendations**:
1. Add `__Host-` prefix to CSRF cookie in double-submit mode for stronger origin binding
2. Set `SameSite=Strict` on CSRF cookie by default
3. Consider deferring token rotation to after successful response generation

### 5. CORS + CSP + Security Headers

**Data Flow**: Request → `CorsMiddleware::handle()` → Origin validation → Header injection → Response → `CspMiddleware::handle()` → CSP header → Security headers

**File locations**: `Middleware/CorsMiddleware.php` (55 lines), `Middleware/CspMiddleware.php` (51 lines)

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **S** | Origin spoofing with wildcard CORS | **High** | `CorsMiddleware.php:20`: When `CORS_ALLOWED_ORIGINS=*`, any origin is allowed. Combined with `Access-Control-Allow-Credentials: false` (`CorsMiddleware.php:21`), cookie-based auth is protected, but token-based auth is still exposed to XSS on any origin. |
| **S** | null origin bypass | **Medium** | `CorsMiddleware.php:52`: `$origin === 'null'` returns empty string (denied). **Mitigated.** |
| **T** | CSP bypass via `unsafe-inline` in fallback | **Medium** | `CspMiddleware.php:22`: Default policy uses `strict-dynamic` with nonces. CSS uses `unsafe-inline` which is required for frameworks but allows CSS injection. |
| **I** | Missing security headers | **High** | `CspMiddleware.php:44-46`: Sets `Content-Security-Policy`, `X-Content-Type-Options`, `X-Frame-Options`. Does NOT set `Strict-Transport-Security`, `Referrer-Policy`, or `Permissions-Policy` by default. |
| **D** | CORS preflight loop | **Low** | `CorsMiddleware.php:23-26`: OPTIONS returns `204` with no body. The `Access-Control-Max-Age` is set to 86400s (24h) by `Router.php:565` only in the OPTIONS handler, not in `CorsMiddleware`. |

**Existing Mitigations**:
- Specific origin validation: `CorsMiddleware.php:49-54` — strict string comparison
- Credentials flag linked to specific origins: `CorsMiddleware.php:21`
- CSP nonce per request: `CspMiddleware.php:28-31` — `random_bytes(16)`
- `frame-ancestors 'none'` prevents clickjacking
- `X-Content-Type-Options: nosniff` prevents MIME sniffing

**Gaps**:
- No HSTS header by default
- No Referrer-Policy header by default
- No Permissions-Policy header by default
- CORS `Access-Control-Max-Age` only set in Router OPTIONS handler, not in CorsMiddleware

**Recommendations**:
1. Add default `Strict-Transport-Security: max-age=31536000; includeSubDomains` when `APP_ENV=production`
2. Add `Referrer-Policy: strict-origin-when-cross-origin` and `Permissions-Policy` headers
3. Set `Access-Control-Max-Age` in CorsMiddleware for consistency

### 6. Rate Limiting (ThrottleMiddleware)

**Data Flow**: Request → `ThrottleMiddleware::handle()` → Redis Lua script (INCR+EXPIRE) / File fallback (flock) → Count check → Rate limit headers → Next middleware or 429

**File locations**: `Middleware/ThrottleMiddleware.php` (235 lines)

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **S** | IP spoofing to bypass rate limits | **High** | `ThrottleMiddleware.php:32`: Rate limiting is keyed by `$request->ip()`. If `APP_TRUSTED_PROXIES` is misconfigured or empty, spoofed `X-Forwarded-For` headers can bypass limits. |
| **T** | Race condition in file fallback | **Low** | `ThrottleMiddleware.php:130-161`: Uses `flock(LOCK_EX)` which is blocking and atomic. Count is read, incremented, and written under lock. **Mitigated.** |
| **T** | Redis Lua script manipulation | **Low** | `ThrottleMiddleware.php:37-45`: Uses `eval()` with KEYS and ARGV as separate parameters. Lua script is hardcoded, not user-controllable. **Mitigated.** |
| **D** | Rate limit bypass via HTTP method/path encoding | **Medium** | `ThrottleMiddleware.php:33`: Key uses `rawurlencode($request->method() . ':' . $request->path())`. Path encoding differences (e.g., `/path vs /path/`) may create separate counters. |
| **D** | File fallback disk exhaustion | **Medium** | `ThrottleMiddleware.php:111-116`: Writes JSON files to `storage/rate_limit/`. Each unique IP+route creates a file. If rate-limited IPs use many routes, disk can fill. |
| **D** | Rate limit bypass via fallback mode 'disabled' | **High** | `ThrottleMiddleware.php:87-91`: When `THROTTLE_FALLBACK=disabled`, throttling is completely bypassed. This is intentional for dev but dangerous in production. |

**Existing Mitigations**:
- Atomic Redis Lua: `ThrottleMiddleware.php:37-45` — INCR + conditional EXPIRE in one script
- File fallback with `flock`: `ThrottleMiddleware.php:130-161` — exclusive lock
- Fallback modes: `ThrottleMiddleware.php:86-100` — `fail_closed` rejects when backend is down
- Rate limit headers: `ThrottleMiddleware.php:62-67,72-78` — client visibility
- Configurable per-route: `ThrottleMiddleware.php:21` — `$maxRequests` and `$minutes` per route

**Gaps**:
- Rate limit key does not include authenticated user ID, only IP
- No distributed rate limit state sharing (works per-server with Redis, but file fallback is server-local)
- File fallback creates unbounded files per unique IP+route combination

**Recommendations**:
1. Include authenticated user ID in rate limit key when available
2. Implement periodic cleanup of stale rate limit files
3. Warn in production check when `THROTTLE_FALLBACK=disabled`

### 7. Input Validation (Validator + FormRequest)

**Data Flow**: Request body → `Request::validate()` → `Validator::make()` → Rule parsing → Strategy matching (21 built-in rules + custom rules) → Error collection

**File locations**: `Validator.php` (394 lines), `FormRequest.php` (82 lines), `ValidationException.php`

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **T** | Regex DoS (ReDoS) via custom regex rule | **High** | `Validator.php:183-191`: `regex` strategy takes a user-supplied pattern. `preg_match()` with malicious patterns (e.g., `^(a+)+$`) can cause catastrophic backtracking. |
| **T** | Rule injection via pipe character | **Medium** | `Validator.php:219`: Rules are split by `|`. If a user value can influence the rules string (unlikely in normal use, but possible via config), injection is possible. |
| **I** | Information disclosure via validation error messages | **Low** | `Validator.php:350-358`: Error messages are translated via `Lang::get()`. Default messages reveal field names but not sensitive data. |
| **D** | ReDoS via validator rule looping | **Medium** | `Validator.php:207-343`: Each field iterates all rules. Complex custom rules or many fields could cause CPU exhaustion. |
| **E** | Type confusion bypass | **Medium** | `Validator.php:210-217`: Arrays are rejected for non-file rules. But objects, resources, or callables may pass through without proper type checking in `min`/`max` strategies (`Validator.php:113-164`). |

**Existing Mitigations**:
- Array rejection for non-file rules: `Validator.php:210-217`
- Nullable check: `Validator.php:220,251-253`
- Database-backed `unique` and `exists` rules use parameterized queries: `Validator.php:306-342`
- Registerable custom rules with sandboxed callbacks: `Validator.php:40-43`
- Language-translated error messages: `Validator.php:350-358`

**Gaps**:
- No regex pattern validation/sanitization (ReDoS risk in custom `regex` rule)
- No max validation rule count limit
- `Validator.php:186`: `preg_match()` with `@` suppression — errors are caught but might miss actual pattern issues

**Recommendations**:
1. Add PCRE backtrack/recursion limits before executing user-supplied regex patterns
2. Limit number of validation rules per field
3. Add input length limits to prevent runaway `preg_match` operations

### 8. Database / ORM (QueryBuilder + Model + SqlCompiler)

**Data Flow**: QueryBuilder → `SqlCompiler::buildSelectQuery()` → SQL compilation → PDO prepare + execute → Result hydration → Model objects

**File locations**: `DB/QueryBuilder.php` (832 lines), `DB/SqlCompiler.php` (473 lines), `Model.php` (538 lines), `DB/DatabaseInstance.php`

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **T** | SQL injection via raw expressions | **High** | `QueryBuilder.php:127-136`: `whereRaw()` accepts raw SQL with optional bindings. If application code passes unsanitized input, SQL injection is possible. |
| **T** | Second-order SQL injection | **Medium** | `SqlCompiler.php:56-59`: Identifier quoting checks for `;`, `--`, `/*`. But column names stored in DB and re-used in queries could bypass this. |
| **T** | Mass assignment via missing $fillable | **High** | `Model.php:63-80`: When `$fillable` is empty, a warning is triggered and all mass assignment is blocked. But `forceFill()` (used by `hydrate()`) bypasses fillable checks entirely at `Model.php:509-516`. |
| **I** | Data leakage via eager loading | **Medium** | `Model.php:412-434`: `with()` can eager-load any relationship. If authorization is not checked at the query level, related data may be exposed. |
| **I** | Cache poisoning via query caching | **Medium** | `QueryBuilder.php:829-831`: Cached query results use prefix `qb:<table>:`. Cache key is derived from SQL + bindings. No per-user isolation. User A's restricted query cache could be served to User B. |
| **D** | Unbounded query results | **Medium** | `QueryBuilder.php:232-240`: No default limit on `get()`. Large tables without explicit `limit()` could cause memory exhaustion. |
| **D** | N+1 query problem for eagerly loaded relations | **Low** | `ModelRelations.php`: Eager loading uses separate queries. Without proper indexing, this can be slow but not a direct DoS. |

**Existing Mitigations**:
- All queries use PDO prepared statements with parameterized bindings (`SqlCompiler.php:291-302`, `QueryBuilder.php:744-760`)
- Identifier quoting: `SqlCompiler.php:48-82` — rejects dangerous characters
- Operator whitelist: `SqlCompiler.php:462-472` — only allows `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `LIKE`, `NOT LIKE`
- `$fillable` protection: `Model.php:63-80` — blocks mass assignment on unprotected fields
- Query caching with table-aware invalidation: `QueryBuilder.php:489,523,534`

**Gaps**:
- `whereRaw()` is a bypass around parameterized query protection
- `forceFill()` bypasses `$fillable` checks during hydration
- Query cache has no user-level isolation
- No pagination limit enforcement

**Recommendations**:
1. Add a static analysis rule or runtime warning for `whereRaw()` usage
2. Add per-user context to query cache keys or disable query caching for authenticated requests
3. Implement a maximum row limit on unbounded queries

### 9. Cache System (FileDriver + RedisDriver)

**Data Flow**: Application → `Cache::get()/set()` → `CacheInstance` → `FileDriver` or `RedisDriver` → Storage

**File locations**: `Cache/Drivers/FileDriver.php` (137 lines), `Cache/Drivers/RedisDriver.php` (83 lines), `Cache/CacheInstance.php`, `Cache.php`

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **T** | Cache poisoning via key collision | **Medium** | `FileDriver.php:99-103`: Key is sanitized with `preg_replace()` and truncated to 200 chars + SHA1 suffix. Collision on SHA1 is computationally infeasible. Prefix collisions possible if keys differ only in special chars. |
| **T** | JSON deserialization of untrusted cache data | **Medium** | `FileDriver.php:122`: Cache files store JSON. If an attacker gains filesystem write, they can inject arbitrary serialized data. No signature verification on cache entries. |
| **I** | Sensitive data in cache files | **High** | `FileDriver.php:43-47`: Cache files are stored as plain JSON in `storage/cache/`. If cache is used for sensitive data (e.g., user profiles, API responses with PII), it is readable by any process with filesystem access. |
| **D** | Cache stampede (thundering herd) | **Medium** | `Cache::remember()` does not implement lock-based regeneration. Under high concurrency, multiple requests may simultaneously regenerate the same expired cache entry. |

**Existing Mitigations**:
- SHA1-based filenames prevent predictable file paths: `FileDriver.php:101-102`
- TTL expiration: `FileDriver.php:129-132` — stale entries are deleted on read
- Redis SETEX provides atomic set-with-expiry: `RedisDriver.php:46`
- `LOCK_EX` for concurrent writes: `FileDriver.php:53`
- Prefix-based flush: `FileDriver.php:71-97`, `RedisDriver.php:59-82`

**Gaps**:
- No cache entry encryption for sensitive data
- No cache stampede protection (no mutex/lease)
- No integrity verification on cache entries
- File cache has no automatic garbage collection

**Recommendations**:
1. Add optional encryption for cache entries containing sensitive data
2. Implement stampede protection using Redis SET NX or file locking in `remember()`
3. Add CRC or HMAC to cache entries for integrity verification

### 10. Queue System

**Data Flow**: Application → `Queue::push()` → Database `jobs` table → `Queue::work()` → Lock + execute → Delete (success) or Retry/Failed (failure)

**File locations**: `Queue.php` (400 lines), `SendMailJob.php`

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **S** | Job injection via unauthenticated queue push | **High** | `Queue.php:124-149`: `push()` accepts any class name as `$job`. If an attacker gains database write access or if a controller exposes queue push, arbitrary class instantiation is possible. |
| **T** | Job data tampering in database | **Medium** | `Queue.php:198-199`: `json_decode()` of serialized job data. If DB is compromised, job data can be modified to include malicious parameters. |
| **E** | Arbitrary class instantiation | **Critical** | `Queue.php:208-211`: `new $class()` where `$class` comes from the `job` column. Any autoloaded class can be instantiated. If a class has side effects in its constructor, arbitrary code execution is possible. |
| **D** | Queue worker DoS via long-running jobs | **Medium** | `Queue.php:203-205`: `set_time_limit()` is called before execution. But a job in an infinite loop or deadlock could block the worker beyond the timeout. |
| **D** | Unbounded failed jobs table growth | **Low** | `Queue.php:233-241`: Failed jobs are inserted into `failed_jobs` table. No automatic retention policy. |

**Existing Mitigations**:
- Job timeout via `set_time_limit()`: `Queue.php:274-285`
- Exponential backoff: `Queue.php:264-267` — `5 * 2^(attempt-1)`, max 300s
- Max attempts (default 3): `Queue.php:110`
- Database locking for job processing: `Queue.php:158-186` — transaction + `locked_until` column
- Failed jobs isolation: `Queue.php:233-241` — separate table

**Gaps**:
- No class whitelist for `Queue::push()` — any autoloaded class can be instantiated
- No authentication/authorization for the queue dashboard
- No automatic cleanup of failed jobs
- Job constructor arguments are not validated

**Recommendations**:
1. Implement a class whitelist or interface requirement for queue jobs
2. Add authentication to the queue dashboard HTML
3. Implement configurable retention policy for failed jobs

### 11. Mail System

**Data Flow**: Application → `Mail::to()->subject()->html()->send()` → sendmail (PHP `mail()`) or SMTP (fsockopen + STARTTLS)

**File locations**: `Mail.php` (462 lines), `SendMailJob.php`

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **S** | Email spoofing (no SPF/DKIM/DMARC) | **High** | `Mail.php:272-277`: Headers include `From` address but no SPF, DKIM, or DMARC signing. Recipients cannot verify sender authenticity. |
| **T** | Header injection via subject/body | **High** | `Mail.php:303-304`: `$this->subject` and `$this->body` are used directly in `mail()`. If user input flows to these fields without sanitization, CRLF injection (`\r\n`) can inject arbitrary headers. |
| **T** | Attachment path traversal | **Medium** | `Mail.php:171-189`: `attach()` checks `is_file($path)` but does not restrict the base directory. An attacker controlling the path could attach arbitrary files from the server. |
| **I** | SMTP credentials in memory and logs | **Medium** | `Mail.php:320-322`: `MAIL_USERNAME` and `MAIL_PASSWORD` are read from env and used in cleartext for AUTH LOGIN. If a `Logger::error()` is triggered during SMTP, credentials may leak. Logger sanitization covers `password` fields. |
| **D** | SMTP connection resource exhaustion | **Medium** | `Mail.php:327`: `fsockopen()` with 30s timeout. If the SMTP server is slow or unresponsive, it blocks the request for 30 seconds. Queue-based sending mitigates this partially. |

**Existing Mitigations**:
- STARTTLS support: `Mail.php:339-345` — upgrades to TLS when credentials are provided
- SSL verification configurable: `Mail.php:342-343` — `MAIL_SSL_VERIFY` env
- Queue-based async sending: `Mail.php:238-253` — avoids blocking the request
- Attachment validation: `Mail.php:171-174` — checks `is_file()` and `mime_content_type()`

**Gaps**:
- No header injection sanitization in `sendSendmail()` (`Mail.php:303-304`)
- No DKIM/SPF/DMARC support
- Attachment path not restricted to a specific directory
- BCC recipients included in the `mail()` `$to` parameter `Mail.php:291-293`

**Recommendations**:
1. Sanitize email headers by stripping `\r` and `\n` characters from subject, body, and recipient fields
2. Restrict attachment paths to a configurable allowed directory
3. Add DKIM signing support for email authentication

### 12. Encryption (Encrypter + Hash)

**Data Flow**: Plaintext → `Encrypter::encrypt()` → AES-256-CBC + HMAC → base64 output. Password → `Hash::make()` → bcrypt hash.

**File locations**: `Encrypter.php` (82 lines), `Hash.php` (50 lines)

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **T** | AES-CBC padding oracle attack | **Low** | `Encrypter.php:35-63`: HMAC verification (SHA256) before decryption. Any padding oracle attack is blocked because the HMAC is verified first. **Mitigated.** |
| **T** | Key derivation weakness | **Low** | `Encrypter.php:76-80`: Single master key (APP_KEY or JWT_SECRET) is derived into separate encryption and authentication keys via HKDF-like expansion (`hash_hmac('sha256', 'encryption'|'authentication', rawKey)`). **Adequate.** |
| **I** | IV reuse | **Low** | `Encrypter.php:26`: `random_bytes()` for IV on every encryption. Statistically impossible to reuse. |
| **I** | Encryption key stored in `.env` | **Medium** | `Encrypter.php:69-74`: Keys come from `APP_KEY` or `JWT_SECRET` env vars. If `.env` is exposed, all encrypted data is compromised. |
| **D** | Timing attack on HMAC comparison | **Low** | `Encrypter.php:55`: Uses `hash_equals()` for timing-safe HMAC comparison. **Mitigated.** |
| **T** | Bcrypt cost too low | **Medium** | `Hash.php:22`: Uses `PASSWORD_BCRYPT` with default cost (10). No explicit cost configuration exposed. Default cost may become insufficient over time. |

**Existing Mitigations**:
- Encrypt-then-MAC (HMAC-SHA256 over ciphertext + IV): `Encrypter.php:31`
- Constant-time comparison (`hash_equals`): `Encrypter.php:55`
- Separate encryption/auth keys via HKDF expansion: `Encrypter.php:78-79`
- bcrypt for passwords: `Hash.php:22`
- Key reuse prevention: `Hash.php:22` — each password gets a random salt (bcrypt built-in)

**Gaps**:
- No explicit bcrypt cost configuration in Hash API
- AES-CBC (not authenticated encryption like AES-GCM or XChaCha20-Poly1305)
- Single master key for both encryption and auth (mitigated by HKDF expansion)
- No key rotation mechanism for `APP_KEY`

**Recommendations**:
1. Expose bcrypt cost configuration via `Hash::make()` options
2. Consider migrating to AES-256-GCM for built-in authentication
3. Document and implement key rotation procedure for `APP_KEY`

### 13. Session Management

**Data Flow**: Request → `Session::start()` → Cookie reading → File/Redis load → Data access → `Session::save()` → File/Redis write

**File locations**: `Session.php` (385 lines)

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **S** | Session fixation via predictable session ID | **Low** | `Session.php:296-298`: IDs are `bin2hex(random_bytes(32))` (64 hex chars = 256-bit entropy). CSPRNG-based. **Mitigated.** |
| **S** | Session fixation via cookie injection | **Medium** | `Session.php:59-73`: If a cookie with an existing session ID is provided, the session is loaded only if the ID format is valid and the file exists. But if an attacker provides a valid existing session ID, the victim could be associated with the attacker's session (mitigated by `regenerate()` on privilege change). |
| **T** | Session data tampering (file-based) | **Medium** | `Session.php:326`: Session files are JSON with `LOCK_EX`. No HMAC/integrity check. Any process with filesystem write can modify session data. |
| **I** | Session file path traversal | **Medium** | `Session.php:59,71,306,326`: Session ID is validated against `/^[a-f0-9]{64}$/` at line 59. Path traversal via session ID is prevented. |
| **I** | Session data in cleartext on filesystem | **Medium** | `Session.php:306-316`: Session files are plain JSON. If the session contains sensitive data, it is exposed to anyone with filesystem read access. |
| **D** | File-based session directory exhaustion | **Low** | `Session.php:35,302-303`: Sessions stored in `storage/sessions/`. GC runs via `gc()` (line 253) but must be called externally. Unbounded growth possible. |

**Existing Mitigations**:
- Session ID format validation: `Session.php:59` — strict hex regex
- Secure cookie flags: `Session.php:99-105` — HTTP-only, SameSite=Lax, Secure on HTTPS
- Session regeneration: `Session.php:170-194` — deletes old session file
- File locking for write: `Session.php:326` — `LOCK_EX`
- Garbage collection method: `Session.php:253-285`

**Gaps**:
- No automatic session regeneration on privilege escalation
- No session data integrity (HMAC) for file-based sessions
- No encryption of session data at rest
- Garbage collection must be called externally (no built-in cron)

**Recommendations**:
1. Call `Session::regenerate()` automatically when user role/privilege changes
2. Add optional HMAC integrity for file-based session data
3. Implement automatic GC trigger with probabilistic sampling on session start

### 14. File Upload (UploadedFile)

**Data Flow**: HTTP upload → `Request::fromGlobals()` → `UploadedFile` → `store()` → MIME validation → Extension blocking → Path sanitization → File move

**File locations**: `UploadedFile.php` (258 lines), `Request.php` (`parseUploadedFiles()` at lines 134-176)

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **T** | MIME type bypass via magic byte mismatch | **Medium** | `UploadedFile.php:58-64`: Actual MIME is detected via `finfo_file()` (magic bytes). But `mimeMatchesExtension()` (`UploadedFile.php:206-217`) only checks MIME for known extensions; unknown extensions bypass MIME check (returns `true` at line 211). |
| **T** | Path traversal via directory component | **Low** | `UploadedFile.php:91-102`: Directory is sanitized with regex `^[a-zA-Z0-9_\-/]+$`. Path traversal sequences (`..`, absolute paths) are blocked. Filename uses `basename()` at line 107. **Mitigated.** |
| **T** | RCE via blocked extension bypass | **Medium** | `UploadedFile.php:25`: Blocked extensions include `php`, `phtml`, `phar`, etc. But `.shtml`, `.cgi`, `.pl` are also blocked. If web server executes `.php` via other handlers (e.g., `.php5`, `.suffix`), the block may be incomplete. |
| **I** | Uploaded file disclosure | **Low** | `UploadedFile.php:131,154`: Files stored in `storage/public/` which is intended to be web-accessible. No access control on stored files. |
| **D** | Disk exhaustion via large uploads | **Medium** | `UploadedFile.php:245-249`: `maxSize()` reads PHP `upload_max_filesize` and `post_max_size`. However, the actual size check in `Request.php:84-95` limits body size. Files within limits can still fill disk. |

**Existing Mitigations**:
- `is_uploaded_file()` check: `UploadedFile.php:39` — prevents tampering with `$_FILES['tmp_name']`
- `finfo` MIME detection: `UploadedFile.php:58-64` — reads actual file content magic bytes
- Extension blocklist: `UploadedFile.php:25` — 25 dangerous extensions blocked
- Directory path sanitization: `UploadedFile.php:91-102` — strict regex + realpath validation
- Filename sanitization: `UploadedFile.php:105-113` — `basename()` + safe char regex
- Null byte stripping in route params: `Request.php:245-248`

**Gaps**:
- Unknown extensions bypass MIME-extension matching (`mimeMatchesExtension()` returns `true`)
- No limit on number of uploaded files per request
- No virus/malware scanning
- No content disarm and reconstruction (CDR)

**Recommendations**:
1. Default-deny MIME policy: reject unknown extensions unless explicitly allowed
2. Add configurable maximum number of uploaded files per request
3. Integrate with ClamAV or similar for upload scanning (optional)

### 15. Debug / Error Handling

**Data Flow**: Exception → `App::run()` catch block → `showDebugTrace` check → Stack trace generation → Error response

**File locations**: `App.php` (283 lines), `Response.php`

| STRIDE | Threat | Risk | Details |
|---|---|---|---|
| **I** | Debug stack trace disclosure in production | **Critical** | `App.php:202-222`: When `$this->showDebugTrace` is true (which requires `APP_DEBUG=true` AND `APP_ENV !== 'production'` at `App.php:51-52`), full stack traces with file paths, line numbers, and source context are returned in API responses. If misconfigured, this leaks internal paths, DB schema, and logic. |
| **I** | Debug meta information leakage | **Medium** | `App.php:257-267`: `attachDebugMeta()` adds execution time and memory usage to response. In debug mode, also includes cache status. Could aid attackers in fingerprinting. |
| **I** | Validation error details leakage | **Low** | `ValidationException` returns field-level error messages. In some cases, these reveal schema information (e.g., "email already exists" reveals registered accounts). |
| **D** | Unhandled exception DoS | **Low** | `App.php:202`: All exceptions are caught by the `Throwable` catch block. The framework never crashes on unhandled errors. |

**Existing Mitigations**:
- Debug mode is disabled in production: `App.php:50-53` — `APP_DEBUG` requires `APP_ENV !== 'production'`
- No stack traces in production: `App.php:202-222` — returns `{}` errors without trace
- 500 error with generic message: `App.php:224`
- Request logging with trace ID: `App.php:231`
- Validation exception handling: `App.php:197-201`
- Maintenance mode: `App.php:173-185`

**Gaps**:
- Debug mode guard depends on correct `APP_ENV` configuration
- No middleware-specific error handler (different middleware may have incompatible error handling)
- Application-level error page customization is limited

**Recommendations**:
1. Add a boot-time check that warns if `APP_DEBUG=true` in production (beyond the guard)
2. Add a `showDebugTrace` override via allowed IP addresses for staging debugging
3. Implement a middleware-based error handler for custom error pages

---

## Attack Trees

### Attack Tree 1: JWT Token Forgery

```
Goal: Forge a valid JWT to impersonate any user
├── 1. Algorithm manipulation
│   ├── 1.1 Set alg="none" in token header
│   │   └── Mitigation: Server pins algorithm (JWT.php:144-148)
│   └── 1.2 Downgrade RS256 → HS256 using public key
│       └── Mitigation: Algorithm is pinned server-side, never trusted from header
├── 2. Secret/key compromise
│   ├── 2.1 Read JWT_SECRET from .env
│   │   └── Mitigation: .env in .gitignore, filesystem permissions
│   ├── 2.2 Brute force weak secret
│   │   └── Mitigation: 32-char minimum enforced (JWT.php:267-276)
│   └── 2.3 Leak via error/exception
│       └── Mitigation: Logger sanitization (docs/SECURITY.md:306-328)
├── 3. Token theft (replay)
│   ├── 3.1 Intercept over HTTP
│   │   └── Mitigation: TLS recommended, token TTL (default 1h)
│   ├── 3.2 XSS → localStorage/sessionStorage
│   │   └── Mitigation: CSP with strict-dynamic (CspMiddleware.php)
│   └── 3.3 Man-in-the-middle
│       └── Mitigation: JTI blacklist for revocation (JWT.php:353-366)
└── 4. Claim manipulation
    ├── 4.1 Modify exp/iat/sub
    │   └── Mitigation: HMAC verification fails (JWT.php:199-201)
    └── 4.2 Modify token_version
        └── Mitigation: DB version check (AuthMiddleware.php:96-101)
```

### Attack Tree 2: Database SQL Injection

```
Goal: Execute arbitrary SQL queries
├── 1. Exploit whereRaw() with unsanitized input
│   └── Mitigation: Developer discipline, static analysis recommended
├── 2. Identifier injection via column names
│   ├── 2.1 WHERE clause column
│   │   └── Mitigation: Identifier quoting (SqlCompiler.php:48-82)
│   └── 2.2 ORDER BY column
│       └── Mitigation: Identifier quoting (SqlCompiler.php:48-82)
├── 3. LIKE operator wildcard injection
│   ├── 3.1 % wildcard → data extraction
│   │   └── Mitigation: Parameterized binding treats LIKE value as data
│   └── 3.2 Heavy data extraction via brute force
│       └── Mitigation: Rate limiting, query timeouts
├── 4. Second-order injection
│   ├── 4.1 Store malicious data → use in raw query
│   │   └── Mitigation: Output encoding, input validation
│   └── 4.2 Column name from database → use in query
│       └── Mitigation: Identifier validation (SqlCompiler.php:52-54)
└── 5. Mass assignment via forceFill()
    └── Mitigation: Use fill() instead of forceFill() in application code
```

### Attack Tree 3: File Upload RCE

```
Goal: Execute arbitrary code via uploaded file
├── 1. Upload PHP file
│   ├── 1.1 Direct .php extension
│   │   └── Mitigation: Blocked extension list (UploadedFile.php:25)
│   ├── 1.2 Double extension (file.php.jpg)
│   │   └── Mitigation: Extension detection uses pathinfo() (UploadedFile.php:49)
│   └── 1.3 .htaccess override → AddType application/x-httpd-php .txt
│       └── Mitigation: .htaccess is in blocked list (UploadedFile.php:25)
├── 2. MIME type bypass
│   ├── 2.1 Magic byte manipulation (GIF89a header in PHP file)
│   │   └── Mitigation: finfo detects actual content regardless of extension
│   └── 2.2 Unknown extension → no MIME check
│       └── GAP: mimeMatchesExtension() returns true for unknown ext (UploadedFile.php:211)
├── 3. Path traversal
│   ├── 3.1 Directory traversal in name
│   │   └── Mitigation: basename() strips path (UploadedFile.php:107)
│   └── 3.2 Directory traversal in directory param
│       └── Mitigation: Regex validation (UploadedFile.php:95-102)
└── 4. Overwrite critical files
    └── Mitigation: move_uploaded_file() + safe filename generation
```

---

## Risk Matrix

| # | Threat | Module | STRIDE | Likelihood | Impact | Risk | Existing Mitigation | Gap |
|---|---|---|---|---|---|---|---|---|
| 1 | Debug trace disclosure in production | Debug/Error | I | Low | Critical | **Critical** | `APP_DEBUG` disabled in production (App.php:50-53) | Relies on correct APP_ENV config |
| 2 | Arbitrary class instantiation via queue | Queue | E | Low | Critical | **Critical** | No class whitelisting | Any autoloaded class can be instantiated (Queue.php:208-211) |
| 3 | Email header injection via mail() | Mail | T | Medium | High | **High** | Queue support available | No CRLF sanitization (Mail.php:303-304) |
| 4 | JWT secret brute force | Auth/JWT | S | Low | Critical | **High** | 32-char minimum (JWT.php:267-276) | No rate limiting on auth endpoint |
| 5 | Middleware bypass via OPTIONS | Router | S | Medium | High | **High** | Group middleware applied (Router.php:568) | Route middleware skipped for OPTIONS |
| 6 | CSP missing HSTS default | CORS/CSP | I | High | Medium | **High** | CSP middleware present | No HSTS/Referrer-Policy by default |
| 7 | Regex DoS in validator | Validation | D | Low | High | **Medium** | Custom rules sandboxed | No ReDoS protection (Validator.php:183-191) |
| 8 | SQL injection via whereRaw() | DB/ORM | T | Medium | Critical | **High** | Parameterized queries for all built-in methods | whereRaw() bypasses (QueryBuilder.php:127-136) |
| 9 | Auth failure not in security log | Auth/JWT | R | High | Medium | **Medium** | Logger::error() called (AuthMiddleware.php:138) | Not logged to security audit channel |
| 10 | Cache poisoning via write access | Cache | T | Low | High | **Medium** | SHA1 filenames (FileDriver.php:101-102) | No integrity verification |
| 11 | File upload MIME bypass | File Upload | T | Medium | High | **High** | finfo detection (UploadedFile.php:58-64) | Unknown ext returns true (UploadedFile.php:211) |
| 12 | Mass assignment via forceFill() | DB/ORM | T | Medium | High | **High** | $fillable protection (Model.php:63-80) | forceFill() bypasses (Model.php:509-516) |
| 13 | Session data tampering (file) | Session | T | Low | Medium | **Medium** | LOCK_EX for write (Session.php:326) | No HMAC integrity on session files |
| 14 | Rate limit bypass via IP spoofing | Rate Limit | S | Medium | Medium | **Medium** | Trusted proxy config | IP key alone without user ID |
| 15 | SMTP credential leakage in logs | Mail | I | Low | High | **Medium** | Logger redaction of password fields | Credentials in error messages |
| 16 | Route cache integrity | Router | T | Low | Medium | **Medium** | PHP exit prefix (Router.php:271) | No HMAC signature on cache |
| 17 | CSRF token cookie binding | CSRF | T | Low | Medium | **Medium** | hash_equals() | No `__Host-` prefix on cookie |
| 18 | Encryption key in .env | Encryption | I | Low | High | **Medium** | .env in .gitignore | No key rotation mechanism |
| 19 | SQL query cache data leakage | Cache | I | Medium | Medium | **Medium** | Table-prefixed keys | No user-level isolation |
| 20 | Queue disk exhaustion | Queue | D | Low | Medium | **Low** | Default max 3 attempts | No retention policy for failed_jobs |

---

## Final Recommendations

### Top 5 Prioritized by Risk Reduction

1. **🔴 Implement queue job class whitelist** (`Queue.php:208-211`)
   - Restrict `Queue::push()` to classes implementing a `ShouldQueue` interface
   - Prevents arbitrary class instantiation (Critical RCE vector)
   - Effort: Low (add interface + check in push/worker)

2. **🔴 Add CRLF sanitization to Mail headers** (`Mail.php:303-304`)
   - Strip `\r` and `\n` from subject, body, and recipient fields before passing to `mail()`
   - Prevents email header injection (High impact, Medium likelihood)
   - Effort: Low (add `str_replace()` call)

3. **🟠 Add HSTS and missing security headers** (`CspMiddleware.php`)
   - Add `Strict-Transport-Security`, `Referrer-Policy`, and `Permissions-Policy` headers by default
   - Apply to all non-file responses
   - Effort: Low (add 3 header calls)

4. **🟠 Implement auth failure rate limiting** (`AuthMiddleware.php` + `ThrottleMiddleware.php`)
   - Add built-in rate limiting on authentication failures (5 attempts/minute/IP)
   - Log failures to `Logger::security()` channel
   - Effort: Medium (add failure counter + ThrottleMiddleware integration)

5. **🟠 Harden file upload MIME validation** (`UploadedFile.php:206-217`)
   - Change `mimeMatchesExtension()` to deny-by-default for unknown extensions
   - Add configurable allowed MIME types list
   - Effort: Low (change return logic + add config)
