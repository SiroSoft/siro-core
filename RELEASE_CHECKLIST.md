# Siro v1.0 Release Checklist

This checklist is the gate for shipping **v1.0.0**. Every item must be complete
(✅) before tagging. See `ROADMAP_V1.md` for full strategy.

Last updated: 2026-08-27

---

## Phase A — v1.0 Blocker Audit

### A1. Cross-Platform CI
- [x] CI matrix: 3 OS × 3 PHP (8.2, 8.3, 8.4) — workflow ready
- [x] Architecture documented: compatibility ×9, quality gate ×1
- [ ] CI green on all 9 combinations — **PENDING GitHub Actions run**

### A2. Test Suite
- [x] PHPStan level=max: **0 errors**
- [x] PHPUnit: **20,940+ tests / 0 failures**
- [x] `composer validate`: valid

### A3. CLI Commands (95 commands)
- [x] Command inventory: **95 registered** (corrected from 99)
- [x] Smoke test: **95/95** (204 tests, 1178 assertions)
- [x] CLI test isolation: **45 issues → 0 failures**
- [x] Full CLI suite: **480 tests, 3298 assertions, 0 failures**
- [x] Classification: 85 STABLE, 5 EXPERIMENTAL, 5 INTERNAL
- [x] Shell execution audit: all critical paths escaped
- [x] `CLI_AUDIT.md` produced

### A4. Public API Freeze
- [x] API surface inventory: **208 classes, 9 interfaces, 6 traits**
- [x] Classification: STABLE / INTERNAL / EXPERIMENTAL / DEPRECATED
- [x] CLI commands: 95 inventoried with classifications
- [x] Env variables: 68 inventoried
- [x] Middleware contracts: 7 inventoried
- [x] `API_SURFACE.md` produced
- [x] SemVer policy documented

### A6. Security
- [x] `composer audit`: **clean** (0 advisories)
- [x] Shell injection audit: all critical paths use `escapeshellarg()`
- [x] Deploy: `post_deploy` documented as trusted arbitrary shell
- [x] SSRF: `api:test` uses in-process dispatch (NOT a vulnerability)
- [x] Rate limiting: secure default (no trusted proxies)
- [x] JWT: no alg confusion, proper exp/nbf checks
- [x] CSRF: timing-safe `hash_equals`, double-submit pattern
- [x] SQL injection: parameterized by default
- [x] Path security: operations confined to project dir
- [x] Secret exposure: never in output/logs
- [x] Security regression suite: 42 tests, 104 assertions, 0 failures
- [x] P2 hardening: serve/live/fix shell escaping fixed

---

## Phase B — Production Hardening (PENDING)

### B1. 48h Soak Test
- [ ] Duration ≥48h
- [ ] Fatal errors: 0
- [ ] Memory growth <10%
- [ ] DB connection leaks: 0

### B2. Cache/Queue/DB
- [ ] Cache stampede protection
- [ ] Worker restart on fatal
- [ ] Migration rollback safety

---

## Phase C — Release Contract (PARTIAL)

### C1. Documentation
- [x] CHANGELOG.md: v0.41.0 entry
- [x] RELEASE_NOTES.md: v0.41.0 entry
- [x] KNOWN_ISSUES.md: current
- [x] README.md: numbers verified (95 commands)
- [x] API_SURFACE.md: produced
- [ ] UPGRADE.md: complete for 0.x → 1.0
- [ ] SECURITY.md: supported versions table

### C2. Landing & Skeleton
- [x] Landing: benchmark numbers verified
- [x] Landing: forbidden terms clean
- [x] Landing: test count accurate
- [x] Landing: replay messaging risk-aware
- [x] Landing: 95 commands (corrected)
- [x] Skeleton: README matches core
- [x] Skeleton: composer.json compatible

---

## Phase D — RC Dogfood (PENDING)

### D1. Real Application Testing
- [ ] Fresh install via `composer create-project`
- [ ] Fresh install via `siro new`
- [ ] Build API app #1 (CRUD + auth + queue)
- [ ] Build API app #2 (complex queries + cache)

### D2. RC Stability
- [ ] Zero P0 bugs for 2 weeks
- [ ] Zero P1 bugs for 1 week

---

## Release Gate (Final)

- [x] Phase A1: CI matrix ready
- [x] Phase A3: CLI commands verified
- [x] Phase A4: API surface frozen
- [x] Phase A6: Security gate passed
- [ ] Phase B: Production hardening
- [ ] Phase C: Documentation complete
- [ ] Phase D: RC dogfood
- [ ] `composer audit` clean
- [ ] Tag `v1.0.0` on siro-core + SiroPHP
- [ ] Publish to Packagist
- [ ] GitHub Release notes

---

## Sign-off

| Gate | Date | Status |
|------|------|--------|
| A1: Cross-platform CI | 2026-08-27 | ✅ Workflow ready |
| A3: CLI audit | 2026-08-27 | ✅ 95/95 verified |
| A4: API freeze | 2026-08-27 | ✅ Inventory complete |
| A6: Security | 2026-08-27 | ✅ Gate passed |
| B: Production hardening | — | PENDING |
| C: Release contract | — | PARTIAL |
| D: RC dogfood | — | PENDING |

**Maintainer sign-off:** __________ **Date:** __________
