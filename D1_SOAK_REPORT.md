# Phase D1 — Real Application Dogfood Report

**Date:** 2026-09-07
**Environment:** Linux server 222.255.181.133 (Ubuntu, PHP 8.3.6, redis-server 6379/6380)
**Engine under test:** `sirosoft/core` @ `f28e9c8` (branch `fix/queue-time-limit-and-enum-column`)
**Skeleton under test:** `sirosoft/api` v0.40.0 via Packagist (`composer create-project`, `siro new`)

## Verdict: D1 PASS — with 5 real engine bugs found, fixed, and re-verified

Dogfood did exactly what it is designed to do: the fresh-install flows surfaced five
defects that 21k unit tests and CI could not catch, because they live in the gap
between the engine and a real application skeleton.

## 1. Fresh installs (checklist D1 items 1–2)

| Method | App | Result |
|---|---|---|
| `composer create-project sirosoft/api` | dogfood-app1, dogfood-app2 | exit 0, fully scaffolded |
| `siro new dogfood-app3` | dogfood-app3 | scaffold OK → `composer install` → key:generate → 16/16 migrations |

`siro new` creates the project relative to CWD (an absolute target path becomes
`<cwd>/tmp/<name>`) — cosmetic quirk, logged below, not a blocker.

## 2. Application #1 — CRUD + auth + queue

- `php siro migrate --force` → 16/16 migrations (fresh SQLite DB)
- `POST /api/auth/register` → 200 + JWT; `POST /api/auth/login` → 200 + JWT
- `POST /api/products` (with `Authorization: Bearer`) → **201**; validation → **422** with field errors
- `PUT` → 200; `DELETE` → 200; `GET /api/products` → envelope `meta.total` correct
- Queue: `php siro make:job` → `Queue::push` ×3 → `php siro queue:work` →
  **3/3 executed** (job handle wrote real log lines), `jobs` table emptied

## 3. Application #2 — Redis cache

- `.env`: `CACHE_DRIVER=redis` against real redis-server (port 6379)
- `Cache::set/get` round-trip and `Cache::remember` verified programmatically
  (keys `siro:local:dogfood_probe`, `siro:local:dogfood_remember` present in `redis-cli --scan`)
- Full HTTP flow: register → login → 2× POST 201 → LIST `meta.total: 2`

## 4. Engine bugs found by dogfood (all fixed)

| # | Bug | Fix | Commit |
|---|---|---|---|
| 1 | `.env` inline comments (`DB_CONNECTION=sqlite # note`) were parsed into the value → `migrate` fatal `Unsupported DB driver: sqlite # …` on every fresh install (skeleton ships 49 commented lines) | Env parser strips inline comments after values (quote-aware, `#` inside values preserved) | `c5e13f7` |
| 2 | `DatabaseInstance::configure()` swapped config but kept the cached PDO → commands silently hit the previous backend (root cause of the full-suite `MySQLCommandsTest` error on Windows, MySQL80 running) | `configure()` drops cached PDO (read+write) and prepared statements when config changes | `c5e13f7` |
| 3 | Throttle catch-all converted any downstream exception into `429 Rate limiter fallback processing failed` (proven live: a controller error surfaced as 429, then as 500 once fallback disabled) | Middleware rethrows anything that is not its own counter-state failure | `c5e13f7` |
| 4 | Queue whitelist gap: documented flow `make:job → Queue::push → queue:work` dies on first consume — `Job class … is not registered in the allowed jobs whitelist` (3 retries → failed_jobs) | `App::boot()` whitelists every class physically present in `<base>/app/Jobs`; unknown injected classes remain blocked | `f28e9c8` |
| 5 | `make:job` stub did not implement `QueueInterface` → generated jobs fail all retries with `must implement QueueInterface` | Stub now `implements QueueInterface` | `f28e9c8` |

All fixes carry tests where applicable (6 new Env parser tests) and were verified live
on the server after deployment: migrate with original commented `.env`, full CRUD flow,
queue consume 3/3, Redis cache keys.

## 5. B5 — Production gate on Linux (same server, same session)

**PASS.** Full default-config suite: **21,326 tests, 0 failures, exit 0** using
CI-exact tooling (PHPUnit phar 11.5.55, lock-pinned phpstan 2.2.1 → `[OK] No errors`,
isolated Redis on port 6380, writable `TMPDIR`).

Earlier runs' failures were diagnosed as environment artifacts, not product defects:
- 7 Redis/throttle tests: server has ext-redis (CI ubuntu does not) — those tests skip there
- 3 config-cache tests: root-owned `/tmp/storage/framework` (from a root artisan worker) blocked writes — fixed by writable `TMPDIR`
- 1 path-traversal test: depth-dependent (`realpath('../../../etc/passwd')` from a shallow checkout path resolves) — latent test-design issue, noted for a follow-up

## 6. Observations (non-blocking)

- `siro new <absolute-path>` misplaces the project under `<cwd>/tmp/` (path handling)
- `Makefile`/docs mention a skeleton repo `sirosoft/api`; engine repo and Packagist both resolve fine
- Old `v1.0.0` tag (`530e7fe`, 2026-07-25, not on this branch) must be deleted before re-tagging the release

## 7. Remaining for 1.0

- D2: 2 weeks with zero P0 (clock starts at merge; the 5 fixes above came out of D1)
- GitHub Settings: enable Dependency graph (turns `dependency-review` green → 33/33)
- Release mechanics: delete stale `v1.0.0` tag → re-tag at the green release commit → merge PR #74
