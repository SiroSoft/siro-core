# Siro v1.0 Release Checklist

This checklist is the gate for shipping **v1.0.0**. Every item must be complete
(✅) before tagging. Items marked 🔁 are re-run at each release.

Last updated: 2026-08-01

---

## 1. Code Quality Gate 🔁
- [x] PHPStan level=max: **0 errors** (`composer analyse`)
- [x] PHPUnit: **2870+ tests / 5000+ assertions, 0 failures**
- [x] 3/3 consecutive clean test runs (no flakes)
- [x] `composer validate`: valid (no version field, lock in sync)
- [x] API stability baseline test passes (853 public methods, ±15%)
- [x] No debug leftovers (`var_dump`/`dd` only in intentional helpers)

## 2. Security 🔁
- [x] Credentials encrypted on disk (`.siro_auth.json`, `api-test-auth.json`)
- [x] Audit trail HMAC-chained + `audit:verify`
- [x] Replay SSRF hardening (host/path validation)
- [x] `key:generate` requires `--force` in production
- [x] `siro new` never leaks secrets
- [x] No hardcoded secrets in src (gitleaks clean)
- [x] PII redaction in traces (password/token/card...)

## 3. Killer Feature (Why → Replay → Fix → Test → Regression)
- [x] `api:test` writes traces (Why works after test)
- [x] `debug:last` / `api:why` / `db:why` analyze failures
- [x] `replay --force/--diff/--edit/--test` work
- [x] `fix` watcher auto-replays on change
- [x] `test:regression` detects status/JSON regressions

## 4. Cross-Platform & CI 🔁
- [x] CI matrix configured: ubuntu/macos/windows × PHP 8.2/8.3/8.4
- [ ] CI **green on all 3 OS** (pending GitHub Actions run) ← ACTION REQUIRED
- [x] Windows verified locally
- [ ] Linux/macOS verified via CI ← ACTION REQUIRED

## 5. Database
- [x] SQLite: migrate, db:seed, db:backup/restore, db:health/check/stats/optimize
- [x] MySQL: db:backup/health/check/stats/optimize
- [x] db:seed idempotent

## 6. Soak / Production Pilot 🔁
- [x] `scripts/soak-test.php` created + verified (100 req, concurrency 10, 0 errors)
- [ ] Soak run against a real project for 48h+ without errors ← ACTION REQUIRED

## 7. Documentation & Hygiene
- [x] CHANGELOG updated to v0.35.1
- [x] RELEASE_NOTES updated
- [x] KNOWN_ISSUES current
- [x] README badge/install accurate
- [ ] UPGRADE.md covers 0.35 → 1.0 (breaking changes policy) ← ACTION REQUIRED
- [ ] SECURITY.md verified

## 8. Release Mechanics 🔁
- [ ] `composer audit` clean (0 advisories)
- [ ] Tag `v1.0.0` on siro-core + SiroPHP
- [ ] Publish to Packagist (`sirosoft/core` 1.0.0)
- [ ] Draft GitHub Release notes
- [ ] Bump CHANGELOG to `## v1.0.0`

---

## Sign-off
- [ ] CI green on 3 OS
- [ ] 48h soak clean
- [ ] UPGRADE.md done
- [ ] All 3 ACTION REQUIRED items complete

**Maintainer sign-off:** __________ **Date:** __________
