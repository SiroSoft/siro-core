# Phase B1 — Soak Infrastructure Report

## Date: 2026-08-27

## 1. Soak Architecture

```
CLI Harness (this process)
    │
    ├── curl_multi (concurrent batch requests)
    │       ↓
    │   PHP Built-in Server (separate process)
    │       │
    │       ├── /health/live       — health check
    │       ├── /health/mem        — server-side memory (requires PHP-FPM for B2)
    │       ├── /soak/db-read      — SELECT
    │       ├── /soak/db-write     — INSERT
    │       ├── /soak/db-update    — UPDATE
    │       ├── /soak/db-transaction — BEGIN/INSERT/UPDATE/COMMIT
    │       ├── /soak/db-rollback  — BEGIN/INSERT/ROLLBACK
    │       ├── /soak/cache-get    — Cache::get
    │       ├── /soak/cache-set    — Cache::set
    │       ├── /soak/cache-remember — Cache::remember
    │       ├── /soak/cache-expire — short TTL cache
    │       ├── /soak/session-read — Session::get/set
    │       ├── /soak/mixed        — realistic API: read + write + cache
    │       ├── /soak/validate     — validation success + failure
    │       ├── /soak/exception    — controlled exception (failure injection)
    │       ├── /soak/trace-lifecycle — trace capture + cleanup
    │       ├── /soak/log-normal   — normal logging
    │       └── /soak/log-error    — error logging (500)
    │
    └── Metrics collector
            ├── samples.jsonl    — streamed to disk (not accumulated)
            ├── results.json     — summary
            ├── report.txt       — human-readable
            └── acceptance.json  — gate criteria
```

## 2. Workload Distribution

| Category | Routes | Weight | Purpose |
|---|---|---|---|
| Database | db-read, db-write, db-update, db-transaction, db-rollback | 40% | Core ORM/DB path |
| Cache | cache-get, cache-set, cache-remember, cache-expire | 20% | File-based cache |
| Mixed | mixed | 10% | Realistic API pattern |
| Validation | validate (ok + fail) | 5% | Controlled failures |
| Session | session-read | 5% | Session lifecycle |
| Trace/Logger | trace-lifecycle, log-normal, log-error | 5% | Debug/trace paths |
| Health | health/live, health/mem | 1% | Server health |

## 3. Acceptance Criteria

| Criterion | Threshold | Short-run Result |
|---|---|---|
| Duration | ≥ 90% of target | ✅ 30s / 30s |
| Total requests | ≥ 100 | ✅ 17,720 |
| Framework-caused fatal errors | 0 | ✅ 0 |
| Unhandled exceptions | 0 | ✅ 0 |
| HTTP 5xx | 0 | ✅ 0 |
| DB failures | 0 | ✅ 0 |
| Failure rate | < 1% | ✅ 0% |
| Server memory post-warmup growth | < 50% | ⚠️ N/A (built-in server) |
| Memory stability (last 10%) | < 100KB range | ⚠️ N/A (built-in server) |

## 4. Short-Run Evidence

```
Duration:        30 seconds
Mode:            short
PHP:             8.2.30
OS:              Windows

── Requests ──
Total:           17,720
Success:         17,720
Failures:        0
HTTP 5xx:        0
Exceptions:      0
Req/sec:         590.67

── Harness Memory ──
Current:         708 KB
Peak:            832 KB
Stable:          YES

── Acceptance Criteria ──
✅ ALL GATES PASSED
```

## 5. Known Limitations

### Server Memory Monitoring

**Issue**: The PHP built-in server handles each request in a fresh context. `memory_get_usage()` returns 0 from `/health/mem` because the built-in server doesn't share memory across requests.

**Impact**: Cannot measure server-side memory growth during soak.

**Mitigation for B2**:
- Use PHP-FPM + Nginx (production-like stack)
- Monitor FPM worker memory via `/proc/PID/status` or `ps`
- Or use a custom endpoint that writes to a shared file/SQLite

**Recommendation**: For the actual 48h soak, use one of:
1. **PHP-FPM + Nginx** — standard production stack, process memory is observable
2. **FrankenPHP** — if SiroPHP supports it, workers share memory across requests
3. **External monitoring** — `ps`/`top` polling the server process PID

### Harness Memory

Harness memory is stable (~708KB after warmup, peak 832KB). Samples are streamed to disk (not accumulated in memory). No leak in the harness process.

### Workload Scope

The soak covers HTTP + DB + Cache + Session + Trace + Logger. It does NOT cover:
- Queue/worker (not in core framework)
- Scheduler (not in core framework)
- Filesystem operations beyond cache
- External HTTP calls (intentionally — no real services)
- Mail
- WebSocket/Mercure

These are acceptable omissions for B1 because:
- Queue/worker is in the API project, not core
- External services should not be called during soak
- The core framework paths are exercised

## 6. Issues Found

| # | Issue | Severity | Fix |
|---|---|---|---|
| 1 | Server memory returns 0 from built-in server | Design limitation | Use PHP-FPM for B2 |
| 2 | Cleanup failed on non-empty `storage/logs` dir | Bug | Fixed: recursive delete helper |
| 3 | Progress output too verbose (every batch) | UX | Acceptable for short mode |

## 7. Files Created/Modified

| File | Purpose |
|---|---|
| `scripts/soak-harness.php` | Soak harness (rewritten v2) |
| `storage/soak/results.json` | Machine-readable metrics |
| `storage/soak/report.txt` | Human-readable summary |
| `storage/soak/samples.jsonl` | Per-sample data (streamed) |
| `storage/soak/acceptance.json` | Acceptance criteria |

## 8. B2 Command

For the actual 48h soak:

```bash
# Short validation (5 min)
php scripts/soak-harness.php --mode=short --duration=300

# Full 48h soak (requires PHP-FPM + Nginx for accurate memory monitoring)
php scripts/soak-harness.php --mode=full --hours=48 --concurrency=10

# 1-hour soak
php scripts/soak-harness.php --mode=full --hours=1
```

**For B2, recommended setup**:
1. Install PHP-FPM + Nginx on a stable Linux server
2. Configure Nginx to proxy to PHP-FPM
3. Run soak harness with `--port=8080` pointing to Nginx
4. Monitor FPM worker memory externally (separate script)
5. Run for 48h minimum
6. Collect results.json + external memory samples

## 9. Verdict

```
═══════════════════════════════════════════════════════
  B1 PASS — Soak infrastructure ready for 48h validation
═══════════════════════════════════════════════════════

  ✅ Harness exercises real SiroPHP paths (HTTP, DB, cache, session, trace)
  ✅ Acceptance criteria defined with explicit gates
  ✅ Short-run validation: 17,720 requests, 0 failures, all gates pass
  ✅ Samples streamed to disk (no memory leak in harness)
  ✅ Cleanup works (recursive delete)
  ✅ Known limitation documented (server memory requires PHP-FPM)
  ⚠️ Server memory monitoring not functional on built-in server

  B2 REQUIRES:
  - PHP-FPM + Nginx setup for accurate server memory monitoring
  - External memory sampling script
  - 48h minimum duration
  - All acceptance gates must pass
═══════════════════════════════════════════════════════
```
