# Siro v1.0 Release Checklist

This checklist is the gate for shipping **v1.0.0**. Every item must be complete
(✅) before tagging. See `ROADMAP_V1.md` for full strategy.

Last updated: 2026-08-27

---

## Phase A — v1.0 Blocker Audit

### A1. Cross-Platform CI
- [ ] CI matrix: Ubuntu × PHP 8.2/8.3/8.4 — green
- [ ] CI matrix: Windows × PHP 8.4 — green
- [ ] CI matrix: macOS × PHP 8.4 — green
- [ ] Full 3×3 matrix on release branch — green

### A2. Test Suite
- [x] PHPStan level=max: **0 errors**
- [x] PHPUnit: **20,940 tests / 0 failures**
- [x] 3/3 consecutive clean test runs (no flakes)
- [ ] MSI ≥95% on security/auth/routing/database paths
- [x] `composer validate`: valid

### A3. CLI Commands (95 commands)
- [ ] Smoke test all 95 commands (boot, --help, args, exit codes)
- [ ] Production guards verified (--force, --dry-run where needed)
- [ ] Windows path handling verified
- [ ] `CLI_AUDIT.md` produced

### A4. Public API Freeze
- [ ] Inventory: classes, interfaces, traits, public methods
- [ ] Inventory: CLI commands + flags
- [ ] Inventory: config keys, env vars, middleware contracts
- [ ] Inventory: exception hierarchy
- [ ] Classification: STABLE / INTERNAL / EXPERIMENTAL / DEPRECATED
- [ ] `API_SURFACE.md` produced
- [ ] `ApiStabilityTest` baseline tightened to ±5%

### A5. Backward Compatibility
- [x] No breaking changes since v0.35.1
- [ ] `composer create-project sirosoft/api` works
- [ ] `siro new test-app` works
- [ ] No removed/renamed public methods since v0.35

### A6. Security
- [ ] `composer audit` clean (0 advisories)
- [ ] Rate limit bypass audit (IP spoofing, X-Forwarded-For)
- [ ] JWT rotation edge cases (concurrent refresh, expired cascade)
- [ ] CSRF double-submit timing-safe comparison
- [ ] SQL injection fuzz round on QueryBuilder
- [x] PII redaction in traces
- [x] Replay SSRF hardening
- [x] Credentials encrypted on disk
- [x] Audit trail HMAC-chained

---

## Phase B — Production Hardening

### B1. 48h Soak Test
- [ ] Duration ≥48h
- [ ] Total requests/jobs ≥10,000
- [ ] Fatal errors: 0
- [ ] Unhandled exceptions: 0
- [ ] Worker deaths: 0
- [ ] Memory growth <10%
- [ ] DB connection leaks: 0
- [ ] Queue stuck jobs: 0 unexpected
- [ ] 5xx rate: 0 framework-caused
- [ ] Log rotation working

### B2. Cache
- [ ] `Cache::remember()` mutex locking (stampede protection)
- [ ] Cache driver fallback verified

### B3. Queue/Worker
- [ ] Worker restart on fatal error
- [ ] Job retry backoff working

### B4. Database
- [ ] Connection pool reuse verified
- [ ] Migration rollback safety verified

### B5. Error Handling
- [ ] Production error handler (no stack traces)
- [ ] Error response format consistent

---

## Phase C — Release Contract

### C1. Documentation
- [x] CHANGELOG.md — v0.41.0 entry
- [x] RELEASE_NOTES.md — v0.41.0 entry
- [x] KNOWN_ISSUES.md — current
- [x] README.md — numbers verified
- [ ] UPGRADE.md — complete (deprecation + support sections)
- [ ] SECURITY.md — supported versions table verified
- [ ] API_SURFACE.md — new, from A4
- [ ] CLI_AUDIT.md — new, from A3

### C2. Landing & Skeleton
- [x] Landing benchmark numbers match BENCHMARK.md
- [x] Landing forbidden terms clean
- [x] Landing test count accurate
- [x] Landing replay messaging risk-aware
- [x] Skeleton README matches core
- [x] Skeleton composer.json version compatible

### C3. Composer Package
- [x] `composer validate` clean
- [x] No version field in composer.json
- [x] Lock file in sync
- [x] License correct
- [x] Autoload correct

---

## Phase D — RC Dogfood

### D1. Real Application Testing
- [ ] Fresh install via `composer create-project`
- [ ] Fresh install via `siro new`
- [ ] Upgrade existing v0.35.x app
- [ ] Build API app #1 (CRUD + auth + queue)
- [ ] Build API app #2 (complex queries + cache)
- [ ] Production-like deploy (Docker/FrankenPHP)

### D2. RC Stability
- [ ] Zero P0 bugs for 2 weeks
- [ ] Zero P1 bugs for 1 week
- [ ] Load test: 1000 req/min sustained

---

## Release Gate (Final)

- [ ] Phase A complete
- [ ] Phase B complete
- [ ] Phase C complete
- [ ] Phase D complete
- [ ] `composer audit` clean
- [ ] Tag `v1.0.0` on siro-core + SiroPHP
- [ ] Publish to Packagist (`sirosoft/core` 1.0.0)
- [ ] Draft GitHub Release notes
- [ ] Bump CHANGELOG to `## v1.0.0`

---

## Sign-off

| Gate | Date | Sign-off |
|------|------|----------|
| Phase A complete | | |
| Phase B complete | | |
| Phase C complete | | |
| Phase D complete | | |
| v1.0.0 released | | |

**Maintainer sign-off:** __________ **Date:** __________
