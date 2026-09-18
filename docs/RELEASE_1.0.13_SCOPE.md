# Release Scope: v1.0.13

This is the proposed scope for the next Core release. It is intentionally
limited to changes present after tag `v1.0.12` and the current worktree.

## Included

### CRUD generator consistency

- `make:crud --simple` generates the documented direct Model-to-Controller
  shape and does not create unused service/repository classes.
- `make:crud` full mode continues to generate the service and repository
  layers.
- `--without-service` and `--without-repository` remain available as explicit
  opt-outs.
- Generated feature tests cover successful and not-found update/delete paths.
- CLI usage and examples document the generated shapes and flags.

### Documentation and release operations

- Refresh current Core version references and performance documentation.
- Document the audited, approval-gated MCP integration as an adapter over the
  deterministic Core workflow.
- Keep reusable diagnostics, release, and soak helpers under `tools/`.
- Add release hygiene validation for tracked secrets and generated artifacts.

## Explicitly Not Included

- No new runtime API unrelated to CRUD generation.
- No breaking change to Router, Model, Storage, Queue, Mail, or authentication.
- No MCP runtime behavior change; the MCP documentation is informational.
- No soak/load-test harness as a production dependency.
- No automatic version bump, tag, or PHAR publication in this scope document.

## Acceptance Gates

- `composer release:check`
- `composer test`
- `composer analyse -- --no-progress --memory-limit=512M`
- `composer validate --no-check-publish`
- Installer PHPUnit suite
- Landing version, API, link, and security checks
- Generated full and simple CRUD projects pass syntax and focused tests

## Release Decision

The scope is now approved for `v1.0.13`. The release must still pass the full
Core, Installer, and Landing gates before the tag is published.
