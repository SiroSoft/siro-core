# SiroPHP v1.0 Roadmap

**Current version:** v1.0.0
**Target:** v1.0.0
**Philosophy:** Prove the existing 41.5K LOC is stable, portable, secure, upgradeable, and API-stable. Do NOT add features to "earn" v1.0.

Last updated: 2026-08-27

---

## Principles

1. **Freeze features.** From v0.41.0 onward, no new features until v1.0.0 ships.
2. **Prove, don't add.** Every P0 task is about verifying what exists, not building what's missing.
3. **API stability > feature richness.** A v1.0 with 95 solid commands beats a v1.0 with 120 half-tested commands.
4. **Public API freeze is the real v1.0 boundary.** Not test count. Not LOC. Not features.

---

## Phase A — v1.0 Blocker Audit

**Goal:** Find and fix everything that would make v1.0.0 embarrass in production.
**Duration:** Week 1-2
**Exit criteria:** Every item PASS or documented P1/P2 with owner.

### A1. Cross-Platform CI

| Task | Status | Notes |
|------|--------|-------|
| CI matrix: Ubuntu × PHP 8.2/8.3/8.4 | ✅ | CI workflow exists |
| CI matrix: Windows × PHP 8.4 | ❌ | Not running |
| CI matrix: macOS × PHP 8.4 | ❌ | Not running |
| All jobs green on all OS | ❌ | Never run full matrix |
| CI timeout合理 (15-20min) | ✅ | |

**Strategy for CI cost:**
- PR: Ubuntu × PHP 8.2/8.3/8.4 + Windows × PHP 8.4 (4 jobs)
- Release: Full 3×3 matrix (9 jobs)

### A2. Full Test Suite Verification

| Metric | Current | Target | Gap |
|--------|---------|--------|-----|
| Test files | 217 | — | — |
| Unit/integration tests | 2,846 | — | — |
| Fuzz tests | 17,981 | — | — |
| DAST tests | 157 | — | — |
| Total | 20,940 | — | — |
| PHPStan Level Max | 0 errors | 0 errors | ✅ |
| MSI (overall) | ~83% | ≥83% | ✅ |
| MSI (security/auth) | — | ≥95% | Verify |
| MSI (routing) | — | ≥95% | Verify |
| MSI (database) | — | ≥95% | Verify |
| No flaky tests | — | 3 consecutive clean runs | Verify |

**Acceptance:** No surviving Critical/High-risk mutations in security, routing, database, auth, queue, and request lifecycle.

### A3. 99 CLI Commands Audit

Every public CLI command must smoke-pass:

| Check | Per command |
|-------|-------------|
| Command boots without error | ✅/❌ |
| `--help` output correct | ✅/❌ |
| Required args validated | ✅/❌ |
| Invalid args produce clear error | ✅/❌ |
| Exit codes consistent (0=success, 1=error) | ✅/❌ |
| Filesystem side effects documented | ✅/❌ |
| DB side effects documented | ✅/❌ |
| Production guard (`--force` if needed) | ✅/❌ |
| Windows path handling | ✅/❌ |
| Documentation matches behavior | ✅/❌ |

**Output:** `CLI_AUDIT.md` — table of all 95 commands with pass/fail per check.

### A4. Public API Freeze & Inventory

**This is the most important P0 task.** Before v1.0.0-rc1, inventory entire public surface:

```
Namespace inventory
├── Classes (concrete + abstract)
├── Interfaces
├── Traits
├── Public methods per class
├── CLI commands + flags
├── Config keys (.env, config files)
├── Environment variables
├── Middleware contracts
├── Container contracts
├── DB/Model APIs
├── Queue APIs
├── Exception hierarchy
└── Skeleton conventions
```

Then classify each item:

| Classification | Meaning | v1.0 policy |
|---------------|---------|-------------|
| **STABLE** | Public API, backward-compatible across 1.x | Will not break without major version |
| **INTERNAL** | May change without notice | Not part of public contract |
| **EXPERIMENTAL** | Shipped but may be removed | Clearly marked in docs |
| **DEPRECATED** | Will be removed in 2.0 | Warning in docs + runtime |

**Output:** `API_SURFACE.md` — complete inventory with classification.

**Guard:** `ApiStabilityTest` already tracks public method count (853 baseline, ±15%). Tighten to ±5% for 1.0.

### A5. Backward Compatibility Audit

| Check | Notes |
|-------|-------|
| `composer create-project sirosoft/api` works | Fresh install |
| `siro new test-app` works | Fresh project |
| Existing v0.35.x code runs on v0.41.0 | No breaking changes since 0.35 |
| No removed public methods since v0.35 | Git blame check |
| No renamed classes/interfaces | Git diff check |
| No changed method signatures | API baseline test |

### A6. Security Audit

| Check | Status | Notes |
|-------|--------|-------|
| `composer audit` clean | ❌ | 1 high vulnerability on skeleton |
| Rate limit bypass audit | ❌ | IP spoofing, X-Forwarded-For |
| JWT rotation edge cases | ❌ | Concurrent refresh, expired cascade |
| CSRF double-submit timing-safe | ❌ | Verify |
| SQL injection fuzz round | ❌ | QueryBuilder |
| PII redaction comprehensive | ✅ | Traces |
| Replay SSRF hardening | ✅ | |
| Credentials encrypted | ✅ | |
| Audit trail HMAC-chained | ✅ | |

### A7. UPGRADE.md

| Section | Status |
|---------|--------|
| v0.35.x → v1.0.0 overview | ✅ |
| Breaking changes (none expected) | ✅ |
| New features summary | ✅ |
| Upgrade steps | ✅ |
| API stability policy | ✅ |
| Deprecation policy | ❌ Need to add |
| Support matrix (PHP versions) | ❌ Need to add |

---

## Phase B — Production Hardening

**Goal:** Ensure SiroPHP can run in production for 48+ hours without issues.
**Duration:** Week 2-4
**Exit criteria:** Soak test passes all acceptance criteria.

### B1. 48h Soak Test

**Acceptance criteria:**

| Metric | Threshold |
|--------|-----------|
| Duration | ≥48 hours |
| Total requests/jobs | ≥10,000 |
| Fatal errors | 0 |
| Unhandled exceptions | 0 |
| Worker deaths | 0 |
| Memory growth | <10% over 48h |
| DB connection leaks | 0 |
| Queue stuck jobs | 0 unexpected |
| 5xx rate | 0 framework-caused |
| Log growth | Controlled (rotation working) |

**Workload must include:**

- HTTP requests (CRUD operations)
- Database (reads + writes + migrations)
- Cache (remember, forget, flush)
- Session (create, read, destroy)
- Queue (dispatch, process, retry)
- Mail (fake/test endpoint)
- Scheduled jobs
- Trace/debug lifecycle
- Error handling (trigger 4xx/5xx intentionally)

**Script:** `scripts/soak-test.php` exists, needs enhancement for full workload.

### B2. Cache Stampede

| Task | Priority | Notes |
|------|----------|-------|
| `Cache::remember()` mutex locking | P1 | KNOWN_ISSUES: "severity Medium" |
| Verify cache driver fallback (Redis→file) | P1 | |
| Cache prefix isolation | P2 | Multi-app on same Redis |

### B3. Queue/Worker Hardening

| Task | Priority |
|------|----------|
| Worker restart on fatal error | P1 |
| Job retry backoff | P1 |
| Dead letter queue | P2 |
| Worker memory limit | P2 |

### B4. Database Hardening

| Task | Priority |
|------|----------|
| Connection pool reuse | P1 |
| Transaction isolation levels documented | P2 |
| Migration rollback safety | P1 |
| MySQL-specific edge cases | P2 |

### B5. Error Handling

| Task | Priority |
|------|----------|
| Production error handler (no stack traces) | P1 |
| Exception logging completeness | P1 |
| Error response format consistent | P1 |

### B6. Deployment

| Task | Priority |
|------|----------|
| OPcache config guide | P2 |
| FrankenPHP config guide | P2 |
| Filesystem permissions documented | P2 |
| Windows/Linux path differences documented | P1 |

---

## Phase C — Release Contract

**Goal:** Finalize everything user-facing before RC.
**Duration:** Week 4-6
**Exit criteria:** All docs/landing/claims verified against source.

### C1. API Stability Policy

| Document | Status |
|----------|--------|
| SemVer policy (1.0 → 1.1 → 2.0) | ✅ In UPGRADE.md |
| Deprecation policy (2 minor versions notice) | ❌ Need to write |
| Support matrix (PHP 8.2/8.3/8.4) | ❌ Need to write |
| LTS policy (if any) | ❌ Decide |

### C2. Documentation

| Document | Status | Action |
|----------|--------|--------|
| README.md | ✅ Updated | Verify numbers |
| CHANGELOG.md | ✅ Updated | Add v1.0 entry |
| RELEASE_NOTES.md | ✅ Updated | Add v1.0 entry |
| KNOWN_ISSUES.md | ✅ Current | Update resolved items |
| UPGRADE.md | ⚠️ Partial | Complete deprecation + support sections |
| SECURITY.md | ⚠️ Partial | Verify supported versions table |
| CLI.md | ✅ Updated | Verify 95 commands |
| ARCHITECTURE.md | ✅ Updated | |
| LOGGER.md | ✅ Updated | |
| API_SURFACE.md | ❌ New | Create from A4 inventory |
| CLI_AUDIT.md | ❌ New | Create from A3 audit |

### C3. Skeleton & Landing

| Item | Status | Action |
|------|--------|--------|
| Skeleton README matches core | ✅ | |
| Skeleton composer.json version | ✅ ^0.40.0 | |
| Landing benchmark numbers | ✅ Verified | |
| Landing forbidden terms | ✅ Clean | |
| Landing test count | ✅ 20K+ | |
| Landing replay messaging | ✅ Risk-aware | |

### C4. Composer Package

| Check | Status |
|-------|--------|
| `composer validate` | ✅ |
| No version field in composer.json | ✅ |
| Lock file in sync | ✅ |
| No dev dependencies in require | ✅ |
| License correct | ✅ |
| Autoload correct | ✅ |

---

## Phase D — RC Dogfood

**Goal:** Run v1.0.0-rc.1 in real conditions. Fix P0/P1 only.
**Duration:** Week 6-8
**Exit criteria:** Zero P0 bugs after 2 weeks of RC.

### RC Activities

| Activity | Duration | Notes |
|----------|----------|-------|
| Fresh install (`composer create-project`) | Day 1 | |
| Upgrade existing v0.35.x app | Day 1-2 | |
| Build real API app #1 (CRUD + auth + queue) | Week 1 | |
| Build real API app #2 (complex queries + cache) | Week 1-2 | |
| Production-like deploy (Docker/FrankenPHP) | Week 2 | |
| Load test (1000 req/min sustained) | Week 2 | |
| Monitor for 1 week | Week 2-4 | |

### RC Bug Policy

| Severity | Action |
|----------|--------|
| P0 (crash, data loss, security) | Fix immediately, new RC |
| P1 (broken feature, wrong behavior) | Fix before 1.0.0 |
| P2 (cosmetic, minor) | Defer to 1.0.1 |
| P3 (nice-to-have) | Defer to 1.1.0 |

### RC → Stable Promotion

All of these must be true:

- [ ] Zero P0 bugs for 2 weeks
- [ ] Zero P1 bugs for 1 week
- [ ] CI green on full matrix
- [x] Soak test 48h passes — B2_SOAK_REPORT.md (30.45M req, 0 fatals, flat memory)
- [ ] `composer audit` clean
- [ ] `API_SURFACE.md` finalized
- [ ] UPGRADE.md complete
- [ ] All docs match source

---

## Version Plan

```
v0.41.0  (previous — Trace/Replay Level-2)
   │
   ├── v0.41.1  bugfixes if needed
   │
   └── v0.41.2  final pre-RC bugfixes
         │
         ▼
v1.0.0-rc.1  (Phase A+B+C complete)
   │
   ├── v1.0.0-rc.2  (if P0 bugs found)
   │
   └── v1.0.0       (Phase D complete)  ← current
         │
         ▼
v1.0.1  (if needed)
v1.1.0  (Model Events, Accessors, Enum casting)
v1.2.0  (polymorphic, richer resources)
v2.0.0  (if breaking changes needed)
```

**No v0.42.0 or v0.43.0.** Each intermediate version creates release/docs/compatibility overhead. Stay on 0.41.x until ready for RC.

---

## What Goes into v1.1 / v1.2 (NOT v1.0)

These are valuable but NOT blockers for v1.0:

### v1.1 — ORM Deepening

| Feature | Why not v1.0 |
|---------|-------------|
| Model Events (beforeCreate, afterUpdate...) | ORM works without them |
| Accessors/Mutators | Can use raw attributes |
| Enum casting (PHP 8.1+) | Can use string casting |
| Global scopes | Can use query scopes manually |

### v1.2 — Developer Experience

| Feature | Why not v1.0 |
|---------|-------------|
| Polymorphic many-to-many | Rare use case |
| Richer resource generation | Basic resources work |
| Interactive REPL (tinker) | CLI debugging exists |
| CLI aliases (r, t) | Full names work |
| Video walkthrough | Docs sufficient |

---

## Risk Register

| Risk | Impact | Mitigation |
|------|--------|------------|
| CI matrix fails on Windows/macOS | Delay RC | Test locally first, fix in Phase A |
| Soak test reveals memory leak | Delay RC | Phase B early, allocate buffer |
| `composer audit` has unfixable vuln | Block release | Track upstream, fork if needed |
| API surface too large to freeze | Scope creep | Strict classification, cut INTERNAL |
| 95 commands have undiscovered bugs | RC delay | Smoke test all in Phase A |
| Real app dogfood reveals design flaws | Architecture change | Budget 2 weeks for RC fixes |

---

## Sign-off Gates

### Phase A Complete
- [ ] CI green on 3 OS × 3 PHP
- [ ] CLI audit complete (95 commands)
- [ ] API surface inventory complete
- [ ] Backward compatibility verified
- [ ] Security audit clean

### Phase B Complete
- [x] 48h soak passes all criteria — B2_SOAK_REPORT.md
- [x] Cache stampede addressed
- [ ] Error handling verified

### Phase C Complete
- [ ] UPGRADE.md complete
- [ ] API_SURFACE.md finalized
- [ ] CLI_AUDIT.md finalized
- [ ] All docs match source
- [ ] Landing claims verified

### Phase D Complete
- [ ] 2 real apps built and running
- [ ] Fresh install works
- [ ] Upgrade path works
- [ ] Zero P0 for 2 weeks
- [ ] Zero P1 for 1 week

### v1.0.0 Release
- [ ] All Phase A-D gates pass
- [ ] `composer audit` clean
- [ ] Tag v1.0.0
- [ ] Publish to Packagist
- [ ] GitHub Release notes
- [ ] Skeleton updated

---

**Maintainer sign-off:** __________ **Date:** __________
