# Threat Model — Siro Core

## Methodology: STRIDE

| Threat | Definition |
|--------|------------|
| **S**poofing | Impersonating a user or component |
| **T**ampering | Modifying data or code in transit/storage |
| **R**epudiation | Denying an action without audit trail |
| **I**nformation Disclosure | Exposing protected data |
| **D**enial of Service | Disrupting service availability |
| **E**levation of Privilege | Gaining unauthorized access |

---

## DFD (Data Flow Diagram)

```
[Client] → HTTPS → [Router] → [Middleware Pipeline] → [Controller] ─┬→ [Database]
                                                                    ├→ [Cache (File/Redis)]
                                                                    ├→ [Logger]
                                                                    ├→ [Session (File/Redis)]
                                                                    ├→ [Queue DB]
                                                                    └→ [Upload Filesystem]
```

Trust boundary: `[Client]` → `HTTPS` → everything else is **internal** (but with varying trust levels between components).

---

## 1. Spoofing

| ID | Scenario | Risk | Mitigation |
|----|----------|------|------------|
| S-01 | Attacker forges JWT token | **High** | JWT signed with HMAC-SHA256; secret validated in `Auth\JWT.php` |
| S-02 | Attacker reuses stolen session ID | **High** | Session ID validated against storage; idle timeout in `Session.php` |
| S-03 | API key leakage via logs | **Medium** | `Logger::sanitizeHeaders()` strips Authorization header |
| S-04 | Host header injection | **Low** | Only used for routing; no dynamic host-based auth |
| S-05 | CSRF via malicious site | **Medium** | `CsrfMiddleware.php` uses `hash_equals()` token comparison; enabled per-route |

---

## 2. Tampering

| ID | Scenario | Risk | Mitigation |
|----|----------|------|------------|
| T-01 | Route cache file modified on disk | **High** | HMAC-verified cache load in `Router.php`, `Config.php` (both fixed) |
| T-02 | SQL injection via raw queries | **Medium** | All queries use prepared statements in `Database.php` |
| T-03 | Uploaded file extension bypass | **Medium** | Blocked extensions list in `UploadedFile.php`; MIME detection via finfo |
| T-04 | Config file tampering | **Medium** | Config cache verified with HMAC |
| T-05 | Session file <-> Redis data corruption | **Low** | `json_encode` failure check added in `Session.php` |

---

## 3. Repudiation

| ID | Scenario | Risk | Mitigation |
|----|----------|------|------------|
| R-01 | User denies performing an action | **Medium** | `Logger::request()` logs method, path, IP, trace ID, user-agent |
| R-02 | Admin denies configuration change | **Low** | Mitigation not yet implemented: `Config::set()` does not emit events; requires application-layer audit |

---

## 4. Information Disclosure

| ID | Scenario | Risk | Mitigation |
|----|----------|------|------------|
| I-01 | Debug stack trace leaked to user | **High** | Only shown in debug mode; production returns generic 500 |
| I-02 | SQL query logging leaks schema | **Medium** | `Database::getCapturedQueries()` gated by `capture_queries` config flag (not automatically tied to `APP_DEBUG`) |
| I-03 | Error messages reveal field details | **Low** | Validator labels escaped via `htmlspecialchars()` |
| I-04 | Timing attacks on password comparison | **Medium** | `hash_equals()` used for all HMAC comparisons; JWT uses constant-time |
| I-05 | PHP version disclosure in headers | **Low** | Mitigation not yet implemented (`expose_php` / `X-Powered-By` header removal needed in bootstrap) |

---

## 5. Denial of Service

| ID | Scenario | Risk | Mitigation |
|----|----------|------|------------|
| D-01 | Resource exhaustion via large uploads | **High** | `upload_max_filesize` / `post_max_size` enforced; `maxSize()` now correctly parses K/M/G |
| D-02 | Regex ReDoS in route matching | **Medium** | Route pattern matching uses simple segment comparison, not regex |
| D-03 | Cache stampede | **Medium** | Cache with TTL; `Cache::remember()` does NOT have mutex locking — concurrent requests for cold key all regenerate simultaneously |
| D-04 | Session file DoS (fill storage) | **Low** | `Session::gc()` garbage-collects expired sessions |

---

## 6. Elevation of Privilege

| ID | Scenario | Risk | Mitigation |
|----|----------|------|------------|
| E-01 | Path traversal in file uploads | **High** | Directory + filename sanitization in `UploadedFile.php`; realpath() boundary check |
| E-02 | Auth middleware bypass | **Medium** | `AuthMiddleware` runs in pipeline; configurable via `Router::setMiddlewareAliases()` |
| E-03 | Mass assignment via model fill | **Medium** | Guarded/fillable attributes pattern in `Model.php` |
| E-04 | Queue job instantiation from DB | **High** | `class_exists()` gate in `Queue.php`; no autoload abuse |

---

## Threat Surface Summary

| Threat Category | High | Medium | Low |
|-----------------|------|--------|-----|
| Spoofing | 2 | 2 | 1 |
| Tampering | 1 | 3 | 1 |
| Repudiation | 0 | 1 | 1 |
| Information Disclosure | 1 | 2 | 2 |
| Denial of Service | 1 | 2 | 1 |
| Elevation of Privilege | 2 | 1 | 0 |
| **Total** | **7** | **11** | **6** |

All high-risk items are mitigated. Items requiring future work noted with "not yet implemented" — tracked below.

---

## Attack Tree: Session Hijacking

```
Session Hijacking
├── 1. Steal session ID
│   ├── 1.1 XSS (mitigated: CSP headers via `CspMiddleware`)
│   ├── 1.2 Network sniffing (mitigated: HTTPS enforced, `secure` cookie flag)
│   └── 1.3 Log leakage (mitigated: `sanitizeHeaders` strips cookies)
├── 2. Replay stolen ID
│   ├── 2.1 Idle timeout — rejected after inactivity (mitigated: `SESSION_IDLE_TIMEOUT`)
│   └── 2.2 Expired session — rejected (mitigated: `Session::gc()` + Redis TTL)
└── 3. Fixation
    ├── 3.1 Pre-set cookie — rejected if session ID not found in storage (mitigated: validate in `Session::start()`)
    └── 3.2 Primary defense: `Session::regenerate()` on privilege escalation (mitigated: regenerates ID + saves data + deletes old)
```
