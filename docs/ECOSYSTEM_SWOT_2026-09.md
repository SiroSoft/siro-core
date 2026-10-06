# Siro Ecosystem SWOT and Market Strategy

**Date:** 2026-09-16

## Scope

This document evaluates the current Siro ecosystem and defines the strategic
direction for capturing the small and medium business API market.

Included products:

- `siro-core`
- `skeleton.sirophp.com` / SiroPHP
- `admin-nuxt.sirophp.com`
- `admin-next.sirophp.com`
- `erp-lite.sirophp.com`
- `siro-showcase`
- `siro-mcp-server`

## Current Snapshot

### Core

- Current release: `v1.0.12`.
- PHP requirement: `>=8.2`.
- Zero runtime dependencies apart from PHP extensions.
- Router, ORM, database abstraction, migrations, validation, auth, RBAC,
  cache, queue, mail, storage, events and security middleware are available.
- CLI includes CRUD, migration, auth, OpenAPI, Postman, database, trace,
  replay, regression and health workflows.
- OpenAPI command already supports JSON output and Swagger UI generation.
- Full test suite currently passes: `21,362 tests`, `35,769 assertions`,
  `110 skipped`, `1 deprecation`.
- PHPStan max level passes.
- Composer audit passes.

### Skeleton and Showcase

- SiroPHP is the recommended full project skeleton.
- Showcase provides a working business API with auth, products, categories,
  orders, users, posts and tags.
- Showcase is the main proof that the Core can support real business flows.
- SiroPHP was aligned to Core `v1.0.12` during this review.

### Admin Applications

- Nuxt and Next admin applications provide two frontend paths for the same
  backend ecosystem.
- They must consume one shared API contract rather than define separate API
  conventions.

### MCP

- MCP release currently published as `v0.3.0`.
- Local implementation has audited, approval-gated execution, project context,
  path protection and scaffold tools.
- Local MCP tests pass: `129 tests`, `325 assertions`.
- MCP is optional and must remain an adapter over the deterministic Core
  workflow.

## Strategic Position

Siro should not compete with Laravel as a general-purpose ecosystem. The
stronger position is:

> Siro is the fastest way to build and operate a secure business API for small
> and medium projects, with an integrated backend, admin frontend, API
> contract and deployment workflow.

The primary product is the workflow, not the number of framework components.

## SWOT Analysis

## Strengths

### Integrated ecosystem

Siro already has a Core, backend skeleton, two admin frontend paths, an ERP
vertical, a showcase and an AI/MCP adapter. This is stronger than presenting
an isolated micro-framework.

### API-first foundation

The Core already includes routing, ORM, migrations, validation, resources,
pagination, authentication, RBAC, OpenAPI, factories, seeders and feature
tests.

### Production and debugging focus

Trace capture, request explanation, SQL and outbound HTTP tracing,
risk-aware replay and regression-test generation create a potential
differentiator beyond ordinary CRUD frameworks.

### Security and operations mindset

Siro includes rate limiting, CORS/CSRF support, security headers, audit logs,
health checks, queue handling, SBOM generation, dependency audit and
production release checks.

### Technical verification

The Core and MCP currently have large automated test suites and PHPStan max
level validation. This gives a strong base for trust if the claims are kept
aligned with reproducible evidence.

### Focused market opportunity

Siro can target PHP developers, agencies, startups and SMEs building CRM,
ERP, inventory, booking, ecommerce and internal business APIs.

## Weaknesses

### Unclear entry point

There are many repositories and product names. A new developer may not know
whether to start with Core, SiroPHP, Showcase, Studio or an admin project.

### Version and release drift

Core, SiroPHP, Showcase and MCP have not always moved in lockstep. Dependency
drift was found during the review, including SiroPHP using Core `v1.0.11`
while the current Core was `v1.0.12`.

### CRUD generator is not yet a complete module workflow

The generator still needs stronger runtime verification, schema-driven
validation, filter/search/sort support, policy generation, OpenAPI
integration and module health checks.

### API contract is distributed

Routes, controllers, models, resources, OpenAPI documents, admin clients and
ERP implementations can drift unless one source of truth is introduced.

### Small external ecosystem

There are few external packages, independent case studies, community
tutorials and production users compared with Laravel.

### Custom framework surface

The custom ORM, router and conventions provide control but reduce package
compatibility and increase the amount of behavior Siro must maintain itself.

### Slow full verification loop

The Core full suite takes several minutes. Tests need clear quick, targeted,
integration and full tiers to keep development productive.

## Opportunities

### Business API generation

Siro can own the workflow from schema to a working API:

```text
Schema
  -> Migration
  -> Model
  -> Validation
  -> Auth and Policy
  -> CRUD API
  -> OpenAPI
  -> Admin UI
  -> Tests
  -> Deploy
```

### Shared backend and admin contract

One OpenAPI and response contract can serve Nuxt, Next, mobile clients,
TypeScript SDKs, MCP tools and ERP features.

### ERP Lite as a vertical proof

ERP Lite can serve as a real acceptance suite, a starter template, a case
study and eventually a product or SaaS foundation.

### Deterministic generation with optional AI

The CLI and manifest should generate code without AI. AI should only convert
natural language into a validated manifest. This avoids provider and model
dependency.

### Vietnamese SME market

Vietnamese documentation, ERP/CRM/ecommerce presets, deployment examples and
local business workflows can create a focused advantage.

### Debug and replay as a unique selling point

Reliable production trace explanation and safe replay can be more valuable
than a generic claim of framework speed.

## Threats

### Laravel ecosystem dominance

Laravel has mature ORM, authentication, queues, admin products, debugging
tools and thousands of packages. Siro cannot win by matching package count.

### Competing API stacks

NestJS, FastAPI, Symfony, Go and .NET frameworks compete for the same API
projects and may have stronger hiring or ecosystem advantages.

### Trust barrier for a new framework

Users will evaluate maintenance, security response, upgrade paths,
documentation, compatibility and production references before adopting Siro.

### Feature sprawl

Core, admin, ERP, Studio, AI, MCP, SDK and deployment features can fragment
the team if they are not connected by one central workflow.

### Generator blast radius

A generator bug affects every newly created project. Generated code requires
runtime tests, not only syntax checks.

### Overstated claims

Performance and production claims need transparent scope and reproducible
evidence. A failed quickstart damages trust more than a missing feature.

## Strategic Implications

### SO strategy

Use existing Core, admin, ERP, debugging and MCP strengths to deliver one
golden path:

```text
Create project
  -> Generate API
  -> Generate OpenAPI
  -> Connect admin
  -> Run tests
  -> Deploy
```

### WO strategy

Use the ecosystem to remove fragmentation:

- Make a manifest the source of truth for each module.
- Use shared API contract fixtures across Core, skeleton, Nuxt, Next and ERP.
- Keep version matrices synchronized automatically.
- Add runtime tests for generated modules.
- Make SiroPHP the official starting point.

### ST strategy

Avoid direct competition on package count. Compete on:

- Delivery speed.
- Opinionated API conventions.
- Production debugging.
- ERP/CRM/ecommerce presets.
- Vietnamese documentation.
- AI and MCP as optional adapters.

### WT strategy

- Do not expand features before API contract stability.
- Do not release local MCP changes without a tagged release.
- Do not overwrite developer-owned code during regeneration.
- Do not claim production readiness without smoke tests.
- Separate quick and full test workflows.

## Product Direction

The central command should be:

```bash
php siro make:api Product
```

It should generate a deterministic, runnable module containing:

- Migration.
- Model and casts.
- Repository and service where appropriate.
- Request validation.
- REST controller.
- Resource and standard JSON response.
- Routes.
- Policy and permissions.
- Search, filter, sort and pagination.
- Factory and seeder.
- Feature tests.
- OpenAPI documentation.
- Optional TypeScript client.

The Core should provide profiles instead of uncontrolled flag growth:

```bash
php siro make:api Product --profile=api
php siro make:api Product --profile=admin
php siro make:api Product --profile=tenant
php siro make:api Product --profile=full
```

## Recommended Roadmap

## Phase 0: Baseline and release discipline

### Objectives

- Keep Core, SiroPHP, Showcase and MCP versions explicit and synchronized.
- Make all standard Composer checks reliable.
- Keep generated code runnable.

### Deliverables

- Green Core full suite.
- Green SiroPHP full suite after the Core `v1.0.12` update.
- Green MCP full suite.
- Version matrix validation in CI.
- Release checklist that tests a fresh project.

### Completion criteria

```text
composer install in a fresh project
 -> migrate
 -> generate API
 -> run generated tests
 -> generate OpenAPI
 -> boot server
```

must pass without manual repair.

## Phase 1: API golden path

### Objectives

Make business CRUD genuinely fast without AI.

### Deliverables

- `make:api` alias or primary command.
- Stable response and error envelope.
- Schema-aware validation.
- Filter/search/sort/pagination.
- Policy/RBAC generation.
- Runtime generated API tests.
- OpenAPI integration with existing `make:openapi`.
- `module:check`.

### Completion criteria

A new developer can create a protected Product API with filtering,
pagination, Swagger and tests in 15 minutes.

## Phase 2: Manifest workflow

### Objectives

Make generation repeatable and safe to evolve.

### Deliverables

- `.siro/modules/Product.yaml` or equivalent manifest.
- Generate from manifest.
- `module:diff`.
- `module:upgrade`.
- Generated/handwritten file ownership rules.
- Shared API contract fixtures.

### Completion criteria

Changing a manifest produces a reviewable plan and does not overwrite
developer-owned code.

## Phase 3: Ecosystem integration

### Objectives

Make all Siro products consume the same API contract.

### Deliverables

- Core `v1.0.12` or newer in SiroPHP.
- Shared OpenAPI fixtures.
- Generated TypeScript client.
- Nuxt admin integration.
- Next admin integration.
- ERP Lite acceptance suite.
- Shared auth, pagination, error and permission format.

### Completion criteria

One generated module can be consumed by both admin frontends without custom
API adapters.

## Phase 4: Production business features

### Objectives

Win practical SME projects rather than benchmark-only comparisons.

### Deliverables

- Audit log.
- Soft delete and restore.
- Bulk operations.
- Import/export.
- Multi-tenancy basics.
- Docker/deployment starter.
- Queue and async job templates.
- ERP/CRM/ecommerce presets.

### Completion criteria

ERP Lite can be rebuilt or extended mainly through the same API workflow used
by a new customer project.

## Phase 5: Optional AI and MCP

### Objectives

Make AI accelerate the same deterministic workflow.

### Deliverables

- Natural language to manifest conversion.
- Preview and approval before mutation.
- MCP execution over the Core generator.
- Audit and replay.
- No direct uncontrolled AI writes to project files.

### Completion criteria

The same module generated by CLI and AI is structurally equivalent and passes
the same validation and test gates.

## Product Metrics

The following metrics matter more than raw feature count:

- Time from project creation to first protected API: under 15 minutes.
- Time from schema change to updated API/OpenAPI/admin client: under 5 minutes.
- Generated module runtime test pass rate: 100 percent on supported profiles.
- Fresh-project quickstart success rate: 100 percent in CI.
- API contract drift between backend and admin: zero.
- Number of manual files required for a standard CRUD module: minimal.
- Time to diagnose a failed production request using trace workflow: under 5 minutes.

## What Not To Do Now

- Do not rewrite the ORM to compete with Eloquent.
- Do not pursue microservices before the modular monolith workflow is strong.
- Do not make AI mandatory for code generation.
- Do not add dozens of generator flags without profiles.
- Do not build a large admin product before the shared API contract is stable.
- Do not release features that are not exercised by Skeleton or ERP Lite.

## Final Decision

Siro should be developed as a deterministic business API workflow platform:

```text
Manifest or CLI
  -> Secure API module
  -> OpenAPI and SDK
  -> Nuxt/Next admin
  -> ERP vertical proof
  -> Tests and deployment
  -> Optional AI/MCP automation
```

The ecosystem should follow one rule:

> Every new feature must make the central API workflow faster, safer, easier
> to integrate or easier to sell.
