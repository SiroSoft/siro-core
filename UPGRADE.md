# Upgrade Guide — SiroPHP v1.0

## Overview

v1.0.0 is the first stable release with an **API stability promise**. From this
version forward, public API changes that break compatibility require a major
version bump. The public method surface is guarded by `ApiStabilityTest`.

**There are no breaking changes from v0.41.0 to v1.0.0.** All code written
against v0.41.x continues to work unchanged.

---

## v1.5.2 → v1.5.3 (2026-11-28)

**There are no breaking changes from v1.5.2 to v1.5.3.** This is a
bug-fix + cleanup release: six small CLI behaviour fixes from the CLI
eval (§3 — `tinker` TTY exit code, `queue:work --tries`/`--sleep`,
`api:contract` strictness, `log:export` CSV deprecation), the `Cli`
testsuite is now part of the release gate, and a whole-repo phpcs
cleanup (1,118 errors + 912 warnings → 1 error + 15 warnings kept
deliberately). No API changes; no migration needed.

---

## v1.5.1 → v1.5.2 (2026-11-28)

**There are no breaking changes from v1.5.1 to v1.5.2.** This is a
performance patch: `App::discoverPackageProviders()` no longer parses the
whole `vendor/composer/installed.json` on every boot when no package declares
a provider (boot min 1.72–1.83 ms → 0.43–0.44 ms). No API changes; no
migration needed.

---

## v1.5.0 → v1.5.1 (2026-11-28)

**There are no breaking changes from v1.5.0 to v1.5.1.** This is a bug-fix
patch: OAuth2 authorize error handling (O2), `make:openapi` empty-spec exit
code (O3), CLI command-count assertions, `make:crud` seeder/migration fixes,
`db:explain` forwarding, two new commands (`schedule:list`, `route:cache`),
and 50 CLI runtime fixes from the full smoke test. No API changes; no
migration needed.

---

## v1.4.2 → v1.5.0 (2026-11-27)

**There are no breaking changes from v1.4.2 to v1.5.0.** All features below
are opt-in: the legacy error envelope stays the default, the old flat
`throttle` signature keeps working unchanged, and OAuth2, webhooks and the
contract gate add nothing unless explicitly enabled/used.

### OAuth2 partner authentication (opt-in)

G2 adds optional OAuth2 partner authentication (`docs/OAUTH2.md`):
`league/oauth2-server` is a `suggest` dependency only — existing apps install
nothing new and boot unchanged. To enable it, run `composer require
league/oauth2-server laminas/laminas-diactoros`, `php siro make:oauth-keys`,
then `php siro make:oauth-client`. `Auth/JWT.php` gains an optional `scope`
claim; existing JWTs without a scope keep working exactly as before.

### RFC 7807 Problem Details (opt-in)

`Response::problem()` already existed but had no callers. v1.5.0 wires it up:
`App::run()` now returns a problem-details body when the client asks for it.

| How to opt in | Effect |
|---|---|
| `Accept: application/problem+json` | That request's errors come back as RFC 7807 (`type`/`title`/`status`/`detail`/`instance`, plus `errors` for validation) with `Content-Type: application/problem+json`. |
| Register API version 2 (`VersionMiddleware::register(2)`) | Problem details become the default error format for that app. |
| Config `api.problem_details_default = true` or env `SIRO_PROBLEM_DETAILS_DEFAULT=1` | Problem details become the default app-wide. |

Stable `type` URIs: `https://siro.dev/problems/validation-failed` (422),
`.../not-found` (404), `.../forbidden` (403), `.../malformed-body` (400),
`.../internal-server-error` (500). The 500 `detail` is always the generic
`Internal Server Error` — the exception message is never leaked.

Migration required: none, unless you want the new format (pick one row above).

### Throttle tiers (opt-in)

`ThrottleMiddleware::define('pos', 600, 1, 'user')` registers a named limiter;
routes use it with `->middleware(['throttle:pos'])`. Key strategies: `'ip'`
(default), `'user'`, `'api_key'`, or a callable `(Request): string`. Plans from
API keys map to limiters with `ThrottleMiddleware::definePlan('partner', 'partner')`.

`rate:status` now lists defined tiers and shows each entry's tier. The old
call `->middleware(['throttle:60,1'])` (flat limit) keeps working unchanged.

Migration required: none. **Follow-up (not in this release):** the `api_keys`
table has no `plan` column yet, so plan-based tiering only fires when the key
data carries a `plan` (e.g. after a future migration adds the column);
`ApiKeyMiddleware` already forwards it when present.
### G5 — signed webhooks (new, opt-in)

Configure endpoints in `config/webhooks.php` (copy the shape from the
core repo's `config/webhooks.php`):

```php
return [
    'endpoints' => [
        ['url' => 'https://partner.example/hook', 'secret' => env('WEBHOOK_SECRET'), 'events' => ['order.created']],
    ],
    'default_secret' => env('WEBHOOK_SECRET', ''),
    'max_attempts' => 3,
    'timeout' => 10,
];
```

```php
use Siro\Core\Webhook\WebhookDispatcher;
use Siro\Core\Webhook\WebhookSigner;

// Dispatch (queues one delivery job per matching endpoint):
(new WebhookDispatcher())->dispatch('order.created', ['order_id' => 42]);

// Verify on the receiving side:
WebhookSigner::verify($rawBody, $request->header('X-Siro-Signature'), $secret);
```

Signature format: `X-Siro-Signature: t={unix_ts},v1={hmac_sha256(ts . '.' . body, secret)}`.
Replays older than 300s are rejected. Delivery retries with exponential
backoff (5/10/20/40/80/160s, capped at 300s) and dead-letters to
`failed_jobs` after `max_attempts`. Run `php siro queue:work` to deliver.

**Replacing SiroERP's `WebhookRetryCommand`:** the app command retried
failed deliveries from its own table. With core G5, dispatch through
`WebhookDispatcher` instead of writing your own delivery rows — retries
and dead-lettering are handled by the queue. Keep the app command (or a
thin wrapper) only for listing/replaying rows already in `failed_jobs`;
new deliveries should go through the dispatcher so the signature,
backoff and dead-letter behaviour are the same everywhere. No change to
the wire format is required on the receiving side beyond verifying
`X-Siro-Signature` (receivers that ignored signatures keep working).

### G7 — OpenAPI contract gate (new, CI only)

- `php siro api:contract --strict --coverage` now fails when a route has
  no spec operation (previously `--strict` alone passed in that case).
  Run it in CI on every PR; keep the committed spec regenerated by
  `make:openapi --force` — hand edits will fail the regenerate-and-diff
  step.
- `make:openapi --with-swagger` now also writes `public/docs/index.html`,
  so the UI is served at `/docs` (`/docs.html` still works).

### G3 — auto-CRUD (new, opt-in)

One line registers a model and gets the 5 standard REST endpoints — no
controller file, no scaffold (see `docs/ROUTER.md`, "Auto-CRUD"):

```php
$router->apiResource(Product::class, [
    'filterable' => ['status', 'category_id'],
    'sortable'   => ['created_at', 'price'],
    'includable' => ['category'],
    'fields'     => ['id', 'name', 'price', 'status'],
    'per_page'   => 15,
]);
```

**Whitelist is mandatory.** Every field/relation used in `filter` / `sort` /
`include` / `fields` must be listed in the matching option. Anything else
returns 400. An option left unset (or empty) means that param is rejected
outright — there is no "allow everything" mode.

**Authorization defaults to DENY.** Every action authorizes through `Gate`
before touching the database. The ability is `abilities[action]` when
configured, otherwise `{table}.{verb}` (`products.view`, `products.create`,
`products.update`, `products.delete`). An ability that was never defined
denies the request (403) — define your abilities (or pass `abilities`)
before exposing an `apiResource()` route, or every call will 403.
Row-level ownership is NOT enforced by auto-CRUD: it authorizes at the
ability level only, so add your own ownership check when a user must only
see their own rows (see `docs/SECURITY.md`).

Migration required: none — nothing changes unless you call `apiResource()`.

### 'json' cast — migration note (changed in v1.4.0, still applies)

v1.4.0 (PR #101) added the missing `case 'json'` in
`ModelSerialization::castAttribute()`: reading a `json`-cast attribute now
returns the **decoded native value** (array, scalar, or `null` for the
literal `'null'`); a stored string that is not valid JSON is returned
untouched instead of being silently swallowed into `[]`. Writing a plain
string now stores it JSON-encoded, so it round-trips. Before v1.4.0 the
declared `json` cast silently never ran, so apps often decoded by hand.

**Action required if you upgrade from ≤1.3.x: remove any hand-decoding of
`json`-cast attributes** — e.g. `json_decode($model->attr)`,
`json_decode((string) $model->attr)`, or a helper like
`Cast::decodeJsonValue($model->attr)`. The attribute is already decoded;
decoding it a second time returns `null` (`json_decode()` on an array
triggers "Array to string conversion"; on a plain non-JSON string it
returns `null`). Search the app for `json_decode(` applied to model
attributes declared with a `json` cast and delete the extra decode.

Found in SiroERP's trial upgrade to 1.5.0-dev (QC1): three tests failed
only because the app decoded twice — variant CRUD and draft-finalize
shipping-address read-back. The fix is app-side (drop the second decode);
nothing in core needs changing.

---

## v0.41.x → v1.0.0

### What changed (non-breaking)

| Area | Change | Migration required |
|------|--------|-------------------|
| **Cache** | `Cache::remember()` now has stampede protection (internal locking) | None — public API unchanged |
| **Queue** | DB queue delivery semantics documented as at-least-once | None — behavior unchanged, documentation clarified |
| **Queue** | Redis queue delivery semantics documented as at-most-once | None — behavior unchanged, documentation clarified |
| **Trace/Replay** | Risky replay requires `--force` (was already in v0.41) | None |
| **CLI** | Command count corrected from 99 to 95 | None — docs updated |
| **Security** | Shell escaping hardened in `serve`, `live`, `fix` commands | None — behavior unchanged |
| **Security** | Deploy `post_deploy` documented as trusted arbitrary shell | None |

### New in v1.0.0 (from v0.41)

| Feature | Description |
|---------|-------------|
| Cache stampede protection | `Cache::remember()` prevents duplicate callback execution under concurrent load |
| Production soak harness | 48-hour soak test infrastructure for runtime validation |
| Cross-platform CI | PHP 8.2/8.3/8.4 × Linux/Windows/macOS matrix |
| CLI smoke tests | All 95 commands verified with data-driven test suite |
| API surface freeze | 208 public classes classified as STABLE/INTERNAL/EXPERIMENTAL |

### Upgrade steps

```bash
# 1. Update the framework
composer update sirosoft/core

# 2. Run the test suite
php siro test

# 3. No migration needed — all public APIs unchanged
```

---

## v0.40.x → v0.41.0

### What changed

| Area | Change | Migration required |
|------|--------|-------------------|
| **Trace/Replay** | Outbound HTTP tracing via `Http::getCapturedCalls()` | None |
| **Trace/Replay** | Queue trace correlation (`_source_trace_id`) | None |
| **Trace/Replay** | Side-effect detection + `--force` for risky replay | Update any automation that replays without `--force` |
| **Trace/Replay** | Generated tests include trace provenance | None |
| **Capture lifecycle** | `Http` capture always cleaned up in `finally` block | None |

### Breaking changes

**`--force` required for risky replays.** If you have scripts that run
`php siro replay <trace>` on traces containing DB writes, outbound HTTP,
or queue jobs, they now require `--force`:

```bash
# Before (v0.40)
php siro replay abc123

# After (v0.41+)
php siro replay abc123 --force   # if trace has side-effect risks
```

GET requests with no detected risks still auto-execute without `--force`.

---

## v0.35.x → v1.0.0

### What changed

| Area | Change |
|------|--------|
| **Security** | HMAC-chained audit trail (`Audit`, `audit:verify`, `audit:log`) |
| **Credentials** | Replay auth tokens encrypted on disk |
| **db:backup/restore** | Real implementation with `.gz` compression |
| **MySQL** | `db:backup/health/check/stats/optimize` support |
| **CLI** | All 95 commands listed in `help` |
| **Quality** | PHPUnit attributes, PHPStan level=max, MSI ~83% |

### Upgrade steps

```bash
composer update sirosoft/core
php siro key:generate --force   # if you rotated APP_KEY
php siro test
php siro audit:verify
```

---

## v0.27.x → v1.0.0

### Behavioral changes

#### Log directory restructured

```
storage/logs/
  daily/2026-05/     ← month-partitioned daily files
  main/              ← cumulative files (rotated at 50MB)
  traces/2026/05/18/ ← date + hash-prefix partitioned
```

**Impact**: Existing log files in the old flat structure are no longer
read by CLI commands (`log:tail`, `log:stats`, etc.).

```bash
# Update custom scripts:
# storage/logs/error-*.log → storage/logs/daily/*/error-*.log
# storage/logs/slow.log → storage/logs/main/slow.log
# storage/logs/traces/*.json → storage/logs/traces/*/*/*/*.json
```

#### Database::execStatement()

```php
// Before
$pdo = Database::connection();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

// After (v1.0)
Database::execStatement('SET FOREIGN_KEY_CHECKS = 0');
```

### New environment variables

```env
LOG_LEVEL=error           # Suppress debug logs in production (default: debug)
LOG_MAX_SIZE_MB=2048      # Total log storage limit in MB (default: 1024)
```

---

## v0.26.x → v0.27.x

- `JWT::encodeAccess()` and `JWT::encodeRefresh()` signatures changed
- `Siro\Core\Auth\JWT` now requires `JWT_SECRET` env var
- `ModelQueryBuilder::where()` signature corrected
- Log directory restructured

---

## API Stability Policy (v1.0+)

- **Patch (1.0.x)**: bug fixes, no public API changes
- **Minor (1.1.x)**: additive public API only (new methods/classes, no removals)
- **Major (2.0)**: breaking public API changes
- Deprecated APIs: documented first, remain functional for one minor version, removed in next major

## Known Limitations (v1.0)

- Redis cache locking: designed but not verified in production Redis environment
- Redis queue: at-most-once delivery (documented, not a bug)
- `pcntl_fork()` multi-worker: Linux/Unix only, Windows falls back to single-process
- `set_time_limit()` timeout: CLI-only, SAPI-dependent
