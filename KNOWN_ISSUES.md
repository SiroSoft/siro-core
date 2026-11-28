# Known Issues & Limitations

## v1.5.2 — Fixed in this release

| Issue | Fix |
|-------|-----|
| Boot touched the 2 ms budget (F-B, re-score 27/11): `App::discoverPackageProviders()` `json_decode`d the whole `vendor/composer/installed.json` (323 KB) on every boot although no package declares a provider | Early return unless the literal key `"siro"` appears in the file (composer always serializes `extra.siro.providers` with that exact key, so a false negative cannot happen); boot min 1.72–1.83 ms → 0.43–0.44 ms (DoD < 2 ms met with margin). Regression tests: no-provider fixture, malformed JSON without the key, declared provider still discovered |

## v1.5.1 — Fixed in this release

| Issue | Fix |
|-------|-----|
| OAuth2 authorize with a wrong `redirect_uri` escaped as a 500 (O2, re-score 27/11) | New bridge helper `AuthorizationServer::authorizationErrorResponse()` converts a League `OAuthServerException` into the proper OAuth error response (401 `invalid_client`, no redirect to the unregistered URI, RFC 6749 §4.1.2.1). Regression tests: unit + real-HTTP E2E |
| `make:openapi` exited 0 with an empty 0-endpoint spec when routes could not be read (O3, re-score 27/11) | Exits 1 and writes no spec when route files exist but nothing could be read. Regression test `testBrokenRoutesExitNonZeroWithoutSpec` |
| `CliTest` / `CommandSmokeAllTest` asserted 96 commands; v1.5.0 ships 102 | Asserts updated to 102 (make: 29) |
| `make:crud --seed` only printed instructions; generated migration had only the `name` column; `db:explain` was a stub that always exited 0 | Real `database/seeds/{Model}Seeder.php` generated; migration now includes all `$fillable` columns; `db:explain` forwards to `DbWhyCommand` (real EXPLAIN, correct exit code), `db:why` accepts `--explain` as a no-op |
| CLI usage strings missing parsed flags (`make:openapi`, `make:oauth-client`, `log:replay`, `make:crud --force`); dead `$sortBy` variable in `log:top` | Usage completed; dead variable removed. New commands: `schedule:list`, `route:cache` (102 commands total); `docs/CLI.md` refreshed to all 102 |
| 50 runtime mismatches found by the 102-command smoke test (~500 runs) | Fixed with regression tests — full list in CHANGELOG v1.5.1: dead `--limit=` / `--method=` flags (off-by-one `substr()`), `api:test` exit codes, `--collection-save` actually saving, bare `--login`, SIGTERM handling (`live`, `log:tail --follow`, `api:test --watch` / `--webhook`, `queue:work --daemon`, `benchmark`), `env:cache` / `optimize` refusing to write a cache without a valid APP_KEY, `env:switch` no longer silently reverting `.env`, webhook job auto-registration + opt-in SSRF bypass, `route:rules` leaking rules across methods, `make:openapi` no longer emitting phantom routes (`App::loadRoutes()` defines `$schedule`), `--host=` scheme doubling, `log:export <trace_id>` exporting only that trace, `help <command>` |
| `PenetrationTest::testEncrypterTamperDetectionBitFlip` flaky (~1/256: the flipped byte could equal the original) | One shared random index for read + write |

## v1.5.0 — Fixed in this release

| Issue | Fix |
|-------|-----|
| `Resource::makeWithFields()` dropped computed fields (e.g. `label`) when `?fields=` was used | Serializes via `Resource::toArray()` first, then intersects |
| `MakeOpenApiCommand::copyToPublic()` ran before `generateSwaggerUi()`, so on a fresh run the UI file did not exist yet and was never copied to `public/` | Order fixed; UI now also copied to `public/docs/index.html`, served at `/docs` |

New capabilities in v1.5.0 (OAuth2, auto-CRUD, RFC 7807 Problem Details, signed webhooks, throttle tiers, contract coverage) are features, not fixes — see CHANGELOG v1.5.0 and `docs/OAUTH2.md`.

> Upgrading from ≤1.3.x: the `json` cast now decodes for real (since v1.4.0, PR #101) — remove any hand-decoding of `json`-cast attributes (`json_decode($model->attr)`, helpers like `Cast::decodeJsonValue(...)`); decoding twice returns `null`. Found in SiroERP's trial upgrade to 1.5.0-dev: 3 tests failed on double-decode. Full note in UPGRADE.md § `'json' cast — migration note`.

## v1.4.1 — Fixed in this release

| Issue | Fix |
|-------|-----|
| `Response::download()` / `stream()` leaked the requested file path in the JSON 404 body (F3) | Generic "File not found" message; traversal case keeps its own "Access denied" message |
| `QueryBuilder::where('col')` with 1 arg silently became `IS NULL` (F1) | Bare 1-arg `where()` / `orWhere()` now throw `RuntimeException`; explicit `where('col', null)` still means IS NULL |
| `orderByRaw()` direction precedence undocumented (F2) | Documented: an ASC/DESC carried by the expression wins over the explicit `$direction`; tests lock the behaviour |
| `new Console('')` poisoned every file-based command — `route:rules` exited 1 (pre-existing `testRouteRules` failure) | Empty basePath resolves from `SIRO_BASE_PATH` (constant, then env var), then `getcwd()` |
| Hardcoded `NUL` proc descriptor in `ApiTestCommandTest` broke the watch-mode deleter on Linux | Platform-aware `/dev/null` / `NUL` (same pattern as the webhook test) |
| `symfony/filesystem` v7.4.11 advisory (CVE-2026-135803, dev-only) | Bumped to the patched 7.4.x; `composer audit` exits 0, so the release-check audit step passes |

## v0.35.1 — Resolved & Current Limitations

### Resolved since v0.26
The following previously-listed issues were fixed in v0.35.1:

| Issue | Fixed in |
|-------|----------|
| `db:backup` / `db:restore` were stubs (reported success, did nothing) | Real `VACUUM INTO` snapshot + validated restore |
| `db:seed` dumped raw PDO stack trace on seeder failure | Wrapped, friendly errors |
| `key:generate` silently rotated production JWT_SECRET | Requires `--force` |
| `siro new` copied real `.env` (secrets) into new projects | Regenerates from `.env.example` |
| `make:crud --simple` referenced a non-existent Resource class | Resource generated |
| `benchmark --iterations=0` crashed (DivisionByZero) | Validated |
| `benchmark --json` polluted with banner text | Clean JSON |
| `config:cache` false success on write failure | Reports error |
| `fix --last` / `api:test` trace gap (Why step missed api:test) | Traces written |
| 30 commands missing from `help` | All 102 listed (v1.5.1) |
| Vietnamese hardcoded messages | English |

### Current Limitations (v1.5.2)
| Issue | Severity | Workaround |
|-------|----------|------------|
| `db:benchmark` SQLite-only (by design — cross-driver benchmarks aren't comparable) | Low | Compare within a driver |
| Cache stampede — `Cache::remember()` no mutex locking | Medium | Acceptable for most workloads |
| No Prometheus push gateway integration | Low | `/metrics` pull endpoint exists |
| `expose_php`/`X-Powered-By` not disabled by default | Low | `expose_php = Off` in php.ini |
| Trace retention not automatic | Low | `log:cleanup --days=N` |
| GitHub Actions checks currently informational (billing/spending limit) — verify locally; Linux/macOS CI runs not yet green | Low | `scripts/release-check.php` locally (PHPUnit + PHPStan max + composer audit) |
| phpcs whole repo: 1 error + 15 warnings kept deliberately (see Unreleased in CHANGELOG) — 1 `MultipleClasses` in `LogReplayExecutionTest` (no PSR-4 mapping to split into), 11 `SideEffects` warnings in CLI entry-point scripts (by design: they declare + execute), 4 heredoc template lines in `MakeCrudCommand` (splitting them would change generator output) | Low | None needed |
| Code coverage baseline not re-measured since v0.40.0 (2026-08-20); no xdebug/pcov driver on this machine as of 2026-11-28 | Low | See COVERAGE.md; current gate metric is 22,462 tests / 41,090 assertions, 0 failures |
| `scripts/soak-harness.php`: `--port=N` has no effect on the health-check/workers (the base URL is built from the default port 18080 before argv is parsed) | Low | Run on the default port |

---

## Architecture Limitations

These are by design, not bugs:

### No Eloquent-style ORM

Siro uses QueryBuilder + Model. Full Eloquem ORM not available.

**Why**: Complexity vs benefit. QueryBuilder covers most use cases.

**Workaround**: Use Model methods + QueryBuilder for complex queries.

### No Web Debug Bar

CLI debugging only. No GUI like Laravel Telescope.

**Why**: Keep zero JS dependencies, framework under 25k lines.

**Workaround**: `log:trace`, `log:replay`, `debug:last`, `why`

### No GraphQL

REST API only.

**Why**: GraphQL adds complexity. REST with cursor pagination covers most needs.

**Workaround**: Use REST + OpenAPI for API docs.

### No WebSocket (SSE via Mercure supported)

HTTP only. No native WebSocket support.
SSE (Server-Sent Events) is supported via [Mercure](https://mercure.rocks/) protocol (`Mercure.php`).

**Why**: Requires different architecture (event loop, workers).

**Workaround**: Use Mercure hub for SSE, or external WebSocket service (Pusher, Ably).

### No Admin Panel

Siro is API-only. No built-in admin UI.

**Why**: Admin panels are app-specific.

**Workaround**: Build with any frontend (Vue, React, Next.js).

---

## Configuration Limits

| Setting | Default | Max | Notes |
|---------|---------|-----|-------|
| Route param length | 255 | 255 | Hard limit |
| Log file size | 50MB | Configurable | Rotation automatic |
| Log retention | 30 days | Configurable | Set `LOG_RETENTION_DAYS` |
| Slow query threshold | 100ms | Configurable | Set `DB_SLOW_QUERY_THRESHOLD` |
| Rate limit (default) | 60/min | 10000 | Per route configurable |
| Upload max size | 8MB | PHP limit | Set in php.ini |

---

## Reporting Issues

Found a bug not listed here?

1. Check existing issues: https://github.com/SiroSoft/siro-core/issues
2. Create new issue with:
   - PHP version
   - Siro core version
   - Reproduction steps
   - Expected vs actual behavior
