# SiroPHP Advantage Plan

Status: implementation plan for the five immediate differentiators.

## Goals

Make the framework prove its advantages with commands, generated artifacts,
repeatable tests, and honest latency measurements.

## 1. Runtime contract verification

Command:

```text
php siro api:contract [--spec=docs/openapi/openapi.json] [--path=/api/products]
```

The command boots the application, loads the OpenAPI document and checks each
documented operation against the registered router without mutating data:

- the route exists and is dispatchable;
- the status code is declared in the operation;
- the operation declares at least one response.

`--strict` turns warnings into failures. The default mode reports all results
and exits non-zero only for missing routes, undeclared status codes, or invalid
OpenAPI JSON. No production network calls or mutating requests are made.

Acceptance: a generated ERP spec can be checked in CI; the command prints
passed/warned/failed counts and returns 0 for a clean contract.

## 2. Migration resume and multi-file OpenAPI

Migration behavior:

- a failed migration is never inserted into `migrations`;
- only an explicit `--force-record` records a failed migration;
- the next run retries the same file;
- existing `already exists` errors remain visible and are not silently marked.

OpenAPI behavior:

- load every `routes/*.php` file in deterministic filename order;
- preserve the existing `api.php` behavior;
- support route files that return a closure and files that register routes as a
  side effect;
- `--routes-dir=` overrides the directory for non-standard layouts;
- duplicate method/path pairs are reported and the last registration wins,
  matching runtime loading order.

Acceptance: migration regression tests prove retry behavior; an app with
`routes/api.php` and `routes/v2.php` emits both route groups.

## 3. Product aggregate cache

The ERP product list will cache the read-only stock aggregate query using the
framework query cache, keyed by sorted product IDs and scoped to the aggregate
query. TTL is 30 seconds. Product, stock, inventory, and conversion writes
invalidate the product aggregate table prefix.

Acceptance: repeated product list requests show a cache hit, writes invalidate
the result, response shape is unchanged, and the benchmark records before/after
numbers. Cache is an optimization only: a cache failure falls back to SQL.

## 4. Concurrent k6 benchmark

Add `backend/scripts/benchmark/k6-products.js` and a documented command:

```text
k6 run -e BASE_URL=https://... -e TOKEN=... \
  backend/scripts/benchmark/k6-products.js
```

Defaults: 1-minute ramp, 100 VUs, GET `/api/v1/products?per_page=5`, checks
for 2xx, and thresholds of `http_req_failed<1%` and `http_req_duration p(95) <
500ms`, `p(99) < 1000ms`. Thresholds are configurable through environment
variables. The script does not create or mutate data.

Acceptance: output records VUs, requests, error rate, p95, p99, timestamp,
commit, and environment in `docs/BENCHMARK.md`; unavailable k6 is reported as
not run, never fabricated.

## 5. Canonical CRUD generator

`php siro make:crud <name> --simple` will mean the canonical notes shape:

- model, migration, repository, service, controller, resource, routes;
- feature test covering index, not-found, create success, and validation;
- no seed unless `--seed` is supplied;
- no RBAC unless `--with-rbac` is supplied.

Full mode remains the same shape and options. `--without-service` and
`--without-repository` remain explicit opt-outs. Existing generated files are
never overwritten without `--force`.

Acceptance: generator tests assert all canonical files, valid PHP syntax, route
markers, and the generated feature test. The quickstart uses the command to
create a copy-runnable module.

## Verification order

1. Core unit tests for commands and cache behavior.
2. Core PHPStan at the repository gate level.
3. ERP backend PHPUnit and PHPStan.
4. ERP dispatch benchmark before/after cache.
5. k6, only when the executable and target are available.

## Non-goals

- no compatibility aliases for undocumented commands;
- no cache of mutable or user-specific responses;
- no claim of network performance without a k6 run;
- no production deployment until Composer points at a tagged core release.
