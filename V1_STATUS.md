# SiroPHP v1.0 — Consolidated Status (2026-09-07)

> This file consolidates everything done toward the v1.0 plan: what is complete and where the
> evidence lives, what remains, and the ordered path to close the release. Figures are sourced
> from `RELEASE_CHECKLIST.md`, `ROADMAP_V1.md`, `B1_SOAK_REPORT.md`, `B2_SOAK_REPORT.md`,
> `D1_SOAK_REPORT.md`, and GitHub Actions.

---

## 1. RELEASED ✅

### Engine `sirosoft/core` v1.0.0 — PUBLISHED

| Item | Status |
|---|---|
| Tag `v1.0.0` | ✅ → commit `932b98a` (main) |
| GitHub Release page | ✅ **published** — `SiroPHP Core v1.0.0 — First Stable Release` (highlights, gate evidence table, upgrade notes) |
| CI on `932b98a` | ✅ 31/34 green; the 3 reds were automation/config bugs now fixed on main (see below) |
| Fresh `composer require sirosoft/core:^1.0.0` | ✅ engine 1.0.0 installs |

### Skeleton `sirosoft/api` v1.0.0 — PUBLISHED

| Item | Status |
|---|---|
| Tag `v1.0.0` | ✅ → main tip `8b02de1` (PR #81 merged) |
| Engine requirement | ✅ `sirosoft/core: ^1.0.0`, lock pins engine `v1.0.0` |
| Packagist | ✅ serving `sirosoft/api` v1.0.0 requiring core `^1.0.0` |
| `composer create-project sirosoft/api` (fresh) | ✅ verified — installs **`sirosoft/core v1.0.0`**, app boots |
| Skeleton CI Release gate | ✅ **success** (`release:check`, 742 tests / 0 failures) |

> ⚠️ Cosmetic: Packagist still resolves the `v1.0.0` tag to an intermediate ref (`5563fd4`) from
> before the CI/test fixes. That ref already pins engine `^1.0.0` + lock `v1.0.0`, so users get the
> right engine — but the served lock also carries dev-only `phpcs 4.0.1` (advisory). Packagist
> re-syncs on its own within hours; otherwise one "Update" click on the Packagist page fixes it.

---

## 2. COMPLETED (evidence in repo)

### Phase A — Blocker audit

| Item | Status | Evidence |
|---|---|---|
| A1. CI matrix 3 OS × 3 PHP | ✅ **green** (post-tag fixes on main) | `.github/workflows/test.yml` — after PR #76/#77 merged: all PHPUnit cells, Lint, PHPStan, Mutation, Coverage, Release Gate, gitleaks green |
| A1. PHPUnit hang on Windows | ✅ Fixed | PR #74: proc_open NUL redirect, env fix, STDIN fix, 20-min watchdog + SIGKILL |
| A3. CLI 95 commands | ✅ 95/95 pass | 480 tests, 3,298 assertions, 0 failures |
| A3. CLI test isolation | ✅ 45 issues → 0 | commit `6472f87` |
| A4. API freeze | ✅ | `API_SURFACE.md` — 208 classes, 9 interfaces, 6 traits, 68 env vars |
| A6. Security | ✅ Gate pass | `composer audit` 0 advisories (re-verified Sep 06), shell injection audit, 42 security tests |

### Phase B — Production hardening

| Item | Status | Evidence |
|---|---|---|
| B1. Soak harness | ✅ | `B1_SOAK_REPORT.md`: 17,720 req/30s, 0 failures, 0 5xx, all gates PASS |
| **B2. 48-hour soak** | ✅ **PASS** | `B2_SOAK_REPORT.md` (Sep 06): Linux 6.8 + PHP-FPM 8.3.6, exactly 172,800 s, **30,453,532 requests**, 0 framework fatals, ~0.00013% unexpected 5xx, 0 stampede callbacks, FPM RSS drift +0.03 MB/48 h — no leak |
| B2. Evaluator bug | ✅ Fixed | `harness.php` separates injected vs unexpected 5xx; the original FAIL was a counting artifact |
| B3. Cache stampede (real Redis) | ✅ **PASS** | `Cache::remember()` per-key locking; on the Linux server: 100 concurrent callers → **1 callback**, 100/100 same value, 0 errors |
| B4. Queue at-most-once (real Redis) | ✅ **PASS** | 10,000 jobs via `Queue::push` → processed 10,000/10,000, failed 0, depth 0, 314 jobs/s |

### Phase C — Release contract (docs)

| Item | Status |
|---|---|
| C1. `UPGRADE.md` complete v0.27→v1.0, no breaking changes | ✅ |
| C2. SemVer + deprecation policy | ✅ |
| C3–C9. Contracts: API / queue (ADR-013) / cache / trace / security / install | ✅ |
| C10. Docs consistency (95 commands, benchmark figures match) | ✅ |
| C11. CHANGELOG v1.0.0 entry | ✅ Written in the release-prep changeset |
| C12. Checklist reflects actual status | ✅ |

### Phase D — Dogfood & release gates

| Item | Status |
|---|---|
| **B5. Final production gate (Linux)** | ✅ **PASS** — 21,326 tests / 0 failures / exit 0 (CI-exact tooling: PHPUnit phar 11.5.55, phpstan 2.2.1, isolated Redis 6380) |
| **D1. RC dogfood** | ✅ **DONE** — fresh install ×3 (`create-project` ×2 + `siro new` ×1), apps #1 (CRUD+auth+queue) & #2 (Redis cache) working; **5 real engine bugs found & fixed** |
| D1. Dogfood-driven fixes | ✅ `.env` inline comments, stale-PDO on DB reconfigure (root cause of MySQL flake), throttle masking errors, queue whitelist gap, `make:job` stub (`c5e13f7`, `f28e9c8`) |
| **D2. RC stability clock** | ⏳ **running** — 0 P0 for 2 weeks / 0 P1 for 1 week, from merge 07 Sep |

---

## 3. POST-TAG CI FIXES (on main, not on the v1.0.0 tag)

The `v1.0.0` merge run (`932b98a`) showed 3 reds — all automation/config, now fixed on main:

| Fix | PR / commit | Root cause |
|---|---|---|
| `.gitleaks.toml` allowlist | #77 / `72d1203` | Full-history gitleaks flagged 4 **fake tokens in mutation-test fixtures**; allowlisted → verified 374 commits, no leaks |
| `release.yml` `contents: write` | #77 / `72d1203` | Workflow lacked permission to create the release (raced the API-created release) |
| `slsa.yml` tar self-inclusion | #77 / `72d1203` | Archive was written inside the tar'd directory → `file changed as we read it` |
| Traversal test depth fix + `siro new` absolute paths | #76 / `93cb8a4` | Dogfood quirks; fixed + CI-green |

After these: main is green for every code-quality gate. The only remaining red is `dependency-review`
(a repo setting — see below).

---

## 4. REMAINING (real gaps)

| Item | Who | Blocking? |
|---|---|---|
| **Enable Dependency graph** on GitHub (Settings → Code security & analysis) | You | Cosmetic — makes `dependency-review` green (both repos); not code |
| **Deploy to Kubernetes** (skeleton CI) | You | Only if you want auto-deploy: the workflow needs a **kubeconfig secret** (`KUBECONFIG` is currently empty → job red). Not a code bug; red on main too |
| **Skeleton Packagist re-sync** of `v1.0.0` ref | Auto / You | Cosmetic — engine `^1.0.0` already served; clears on Packagist's next sync or one "Update" click |
| **D2 clock** | The clock | 2 weeks of no P0 from merge (07 Sep) |
| Mutation score ratchet | Later | MSI 21.1% ≥ CI floor 20% (accepted for 1.0, ratchet gradually) |

---

## 5. PATH STATUS (post-release)

| # | Task | Status |
|---|---|---|
| 1 | Enable Dependency graph on GitHub | Pending — you (1 min) |
| 2 | Verify Redis (stampede + queue) | ✅ PASS |
| 3 | B5 `release:check` on Linux | ✅ PASS (21,326 tests) |
| 4 | Phase D1 dogfood | ✅ DONE (5 bugs fixed) |
| 5 | Release mechanics (delete stale tag → re-tag → merge PR #74) | ✅ DONE |
| 6 | **Skeleton `sirosoft/api` v1.0.0** (bump `^1.0.0`, tag, Packagist, create-project verified) | ✅ DONE (PR #81) |
| 7 | Post-tag CI fixes (gitleaks, release perm, SLSA, dogfood quirks) | ✅ DONE (PR #76/#77) |
| 8 | D2: 2 weeks no P0 | ⏳ Running |

---

*Created 2026-09-06; updated 2026-09-07 after the v1.0.0 engine release (PR #74 merge, tag `932b98a`),
post-tag CI fixes (PR #76/#77), and the skeleton `sirosoft/api` v1.0.0 release (PR #81, tag `8b02de1`).*