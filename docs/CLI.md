---
title: C LI
description: SiroPHP C LI reference
sidebar_position: 3
sidebar_label: C LI
---

# CLI Reference

All 102 SiroPHP CLI commands, grouped by category.

> Run `php siro list` to see all commands or `php siro <command> --help` for details.

---

## Package Commands & Auto-Discovery

Siro-core **v1.0.13+** can auto-discover CLI commands and HTTP service providers from any installed Composer package. No manual registration needed.

### How It Works

When `php siro` boots, it reads `vendor/composer/installed.json` and scans every installed package for an `extra.siro` key in its `composer.json`:

#### Registering CLI Commands

```json
{
    "extra": {
        "siro": {
            "commands": {
                "my:command": {
                    "handler": "Vendor\\Package\\MyCommand",
                    "desc": "Do something useful"
                }
            }
        }
    }
}
```

- `my:command` — The command name shown in `php siro list`
- `handler` — Fully qualified class name, must extend `Siro\Core\Console` or implement the same contract
- `desc` — Description shown in help output (optional)

Once declared, the command appears in:
```bash
php siro list              # Shows your command alongside all built-in ones
php siro my:command        # Runs it
```

#### Registering Service Providers

```json
{
    "extra": {
        "siro": {
            "providers": [
                "Vendor\\Package\\ServiceProvider"
            ]
        }
    }
}
```

The provider class needs only a `register(Siro\Core\App $app): void` method:
```php
namespace Vendor\Package;

use Siro\Core\App;

class ServiceProvider
{
    public function register(App $app): void
    {
        // Bind to container, register routes, middleware, etc.
    }
}
```

### Benefits

- **Zero config**: `composer require vendor/package` — everything works immediately
- **No cache files** (no `services.php`, `compiled.php`, etc.)
- **Works with both root and `require`/`require-dev` packages**
- **Graceful fallback**: If `installed.json` is missing or a handler class doesn't exist, the framework silently skips it

---

## Getting Help

```bash
php siro                    # Core workflow overview
php siro list               # List all 102 commands grouped by category
php siro <command> --help   # Detailed help for a specific command
php siro -h                 # Shorthand help overview
php siro --version          # Show version (1.5.0)
```

---

## make:* — Code Generators (29 commands)

| Command | Description | Usage |
|---|---|---|
| `make:auth` | Generate full auth system (login, register, middleware, routes, JWT config) | `php siro make:auth` |
| `make:controller` | Generate controller class | `php siro make:controller <name>` |
| `make:model` | Generate Eloquent-style model | `php siro make:model <name>` |
| `make:migration` | Generate database migration | `php siro make:migration <name>` |
| `make:crud` | Full CRUD with model, migration, repository, service, controller, resource, routes, and feature test | `php siro make:crud <name> [--simple] [--seed] [--force] [--with-rbac] [--without-service] [--without-repository]` |
| `make:service` | Generate service class | `php siro make:service <name>` |
| `make:repository` | Generate repository class | `php siro make:repository <name>` |
| `make:resource` | Generate API resource transformer | `php siro make:resource <name>` |
| `make:middleware` | Generate middleware class | `php siro make:middleware <name>` |
| `make:listener` | Generate event listener | `php siro make:listener <name>` |
| `make:event` | Generate event class | `php siro make:event <name>` |
| `make:job` | Generate queue job class | `php siro make:job <name>` |
| `make:mail` | Generate mail class | `php siro make:mail <name>` |
| `make:test` | Generate PHPUnit test file (--from-trace, --unit) | `php siro make:test <name> [--unit]` / `php siro make:test --from-trace=<id>` |
| `make:factory` | Generate model factory | `php siro make:factory <name>` |
| `make:openapi` | Generate OpenAPI 3.0 spec from route annotations | `php siro make:openapi [--with-swagger] [--routes-dir=] [--tag=TAG] [--flow=auth\|crud] [--output=] [--force] [--title=] [--version=] [--host=] [--method=] [--path=]` |
| `make:postman` | Generate Postman collection JSON | `php siro make:postman [--flow=crud]` |
| `make:lang` | Generate language/localization file | `php siro make:lang <locale> <file>` |
| `make:seeder` | Generate database seeder | `php siro make:seeder <name>` |
| `make:queue-table` | Create migrations for queue jobs table | `php siro make:queue-table` |
| `make:idempotency-table` | Create migration for idempotency table | `php siro make:idempotency-table` |
| `make:apikey-table` | Create migration for API keys table | `php siro make:apikey-table` |
| `make:apikey` | Generate a new API key with scopes | `php siro make:apikey <name> [scopes] [expires_days]` |
| `make:request` | Generate FormRequest class | `php siro make:request <name>` |
| `make:rule` | Generate custom validation rule | `php siro make:rule <name>` |
| `make:observer` | Generate model observer | `php siro make:observer <name>` |
| `make:webhook` | Generate webhook listener | `php siro make:webhook <name>` |
| `make:oauth-client` | Create OAuth2 client | `php siro make:oauth-client <name> [--public] [--redirect-uri=] [--grants=] [--scopes=]` |
| `make:oauth-keys` | Generate OAuth2 keys | `php siro make:oauth-keys [--force]` |

### Examples

```bash
php siro make:crud products                  # Full CRUD with repository, service, and feature test
php siro make:crud orders --simple           # Direct Model -> Controller CRUD, no service/repository
php siro make:crud orders --seed             # CRUD + seeder
php siro make:crud orders --with-rbac        # CRUD with admin RBAC on mutations
php siro make:crud orders --without-service  # Keep repository, skip service layer
php siro make:auth                           # Generate login/register with JWT
php siro make:openapi --with-swagger         # Generate Swagger UI docs
php siro make:apikey "External App" read,write 365
php siro make:oauth-client "Mobile App" --public --redirect-uri=myapp://callback --grants=authorization_code --scopes=read,write
```

---

## New Project (2 commands)

| Command | Description | Usage |
|---|---|---|
| `new` | Create new project from skeleton | `php siro new <name>` |
| `new:project` | Create project via composer | `php siro new:project <name>` |

---

## db:* — Database (17 commands)

| Command | Description | Usage |
|---|---|---|
| `migrate` | Run all pending migrations | `php siro migrate` |
| `migrate:rollback` | Rollback the last batch of migrations | `php siro migrate:rollback [--step=N]` |
| `migrate:status` | Show migration status (pending/ran) | `php siro migrate:status` |
| `migrate:fresh` | Drop all tables and re-run all migrations | `php siro migrate:fresh [--seed] [--force]` |
| `migrate:reset` | Rollback all migrations | `php siro migrate:reset` |
| `migrate:refresh` | Rollback all and re-run migrations | `php siro migrate:refresh [--seed]` |
| `db:seed` | Run all database seeders | `php siro db:seed [class]` |
| `db:show` | Inspect table schema or data | `php siro db:show <table> [--schema] [--limit=N]` |
| `db:health` | Database health check (SQLite + MySQL) | `php siro db:health` |
| `db:check` | Database integrity check (SQLite + MySQL) | `php siro db:check` |
| `db:stats` | Database statistics (SQLite + MySQL) | `php siro db:stats` |
| `db:optimize` | Optimize database (SQLite + MySQL) | `php siro db:optimize` |
| `db:backup` | Backup database (SQLite + MySQL) | `php siro db:backup [--compress]` |
| `db:restore` | Restore SQLite database from backup | `php siro db:restore <file> [--force]` |
| `db:explain` | EXPLAIN query (forwards to db:why) | `php siro db:explain <query_hash>` / `php siro db:explain --query=<sql>` |
| `db:benchmark` | SQLite performance benchmark | `php siro db:benchmark [--iterations=N]` |
| `db:why` | Analyze slow query — EXPLAIN, index suggestion | `php siro db:why <query_hash>` / `php siro db:why --slow` / `php siro db:why --query=<sql>` |

### Examples

```bash
php siro migrate                          # Run all pending migrations
php siro migrate:rollback --step=2        # Rollback last 2 batches
php siro migrate:status                   # Check which migrations ran
php siro migrate:fresh                    # Drop all tables + re-migrate
php siro migrate:fresh --seed             # Drop + migrate + seed
php siro db:seed                          # Seed the database
php siro db:show users                    # Show table data
php siro db:show users --schema           # Show table columns & types
```

---

## log:* — Logs & Debugging (10 commands)

| Command | Description | Usage |
|---|---|---|
| `log:tail` | Tail log files in real-time | `php siro log:tail [--type=request\|error\|slow] [--lines=N] [--follow\|-f]` |
| `log:trace` | View detailed request trace | `php siro log:trace [<id>] [--status=500] [--limit=N] [--full]` |
| `log:replay` | Replay a previous request from trace | `php siro log:replay <trace_id> [--force] [--set key=val] [--format=] [--safe] [--dry-run] [--edit] [--diff] [--seed] [--test] [--auth] [--as=] [--https] [--http] [--insecure]` |
| `log:export` | Export trace in JSON, CSV, or Postman format | `php siro log:export <trace_id> --postman` |
| `log:cleanup` | Clean old trace files | `php siro log:cleanup [--days=N] [--dry-run]` |
| `log:slow` | Show slow requests | `php siro log:slow [--limit=N] [--min=MS]` |
| `log:stats` | Request statistics with ASCII charts | `php siro log:stats [--days=N]` |
| `log:top` | Top slowest APIs by total cumulative time | `php siro log:top [--limit=N] [--min=MS]` |
| `debug:last` | Show why the last request failed (alias: why) | `php siro debug:last` |
| `debug:health` | Debug system health check | `php siro debug:health` |

### Examples

```bash
php siro log:tail --type=error -f          # Follow error log in real-time
php siro log:trace --status=500            # List all failed (500) traces
php siro log:replay abc123                 # Replay (auto-blocks risky traces)
php siro log:replay abc123 --force         # Replay with --force (bypasses risk guard)
php siro log:replay abc123 --dry-run       # Preview curl command without executing
php siro log:export abc123 --postman       # Export as Postman collection
php siro log:cleanup --days=7 --dry-run    # Preview cleanup without deleting
php siro log:slow --min=500                # Requests slower than 500ms
php siro log:stats --days=30               # Stats for last 30 days
```

---

## Test (4 commands)

| Command | Description | Usage |
|---|---|---|
| `test:regression` | Replay all traces & compare responses — detect regressions | `php siro test:regression [--limit=N] [--status=] [--fail]` |
| `test:run` | Run PHPUnit test suite (legacy) | `php siro test:run [--filter=] [--testsuite=]` |
| `api:test` | Test API (--loop, --as=admin/guest) | `php siro api:test <method> <path> [field:value ...]` |
| `test` | Run tests (--filter=, --suite=, --coverage) | `php siro test [--filter=] [--suite=] [--coverage]` |

---

## Replay Safety

Before replaying a trace, Siro analyzes the captured execution context for potential side effects:

- **DB writes**: INSERT, UPDATE, DELETE, REPLACE, TRUNCATE, ALTER, DROP, CREATE detected in SQL queries
- **Outbound HTTP**: External API calls made through Siro's HTTP client
- **Queue jobs**: Async jobs dispatched during the request

```
Potential replay side effects:
  Database writes:   3
  Outbound HTTP:     1
  Queue dispatches:  1

WARNING: This command re-executes the request against the target application.
Database writes, external API calls, queued jobs, emails, or other
application side effects may occur again.
```

**Guard behavior:**
- GET + no risks → auto-executes (safe)
- POST/PUT/DELETE/PATCH → requires `--force`
- Any method + risks detected → requires `--force`

**Important:** Siro detects and warns about side effects. It does **not** sandbox or isolate them. A forced replay of a checkout request may still create database writes, call payment APIs, or dispatch queued jobs.

**Scope:** Outbound HTTP capture works only for requests made through `Siro\Core\Http`. Native `curl_init()`, Guzzle, or other HTTP clients are not captured.

---

## queue:* + schedule:* — Queue & Schedule (6 commands)

| Command | Description | Usage |
|---|---|---|
| `queue:work` | Process queue jobs | `php siro queue:work [--daemon] [--sleep=] [--tries=] [--workers=]` |
| `queue:retry` | Retry failed jobs | `php siro queue:retry <id\|all>` |
| `queue:flush` | Clear all failed jobs | `php siro queue:flush` |
| `queue:status` | Queue statistics dashboard | `php siro queue:status [--failed=N]` |
| `schedule:run` | Run all scheduled tasks | `php siro schedule:run` |
| `schedule:list` | List scheduled tasks with next run | `php siro schedule:list` |

### Examples

```bash
php siro queue:work --daemon              # Process jobs continuously
php siro queue:retry all                  # Retry every failed job
php siro queue:status                     # Show pending/failed counts
php siro schedule:list                    # Show tasks, cron expression, next run
```

---

## server:* — Server & Deploy (5 commands)

| Command | Description | Usage |
|---|---|---|
| `serve` | Start PHP built-in development server | `php siro serve [--port=8080]` |
| `frankenphp:serve` | Start FrankenPHP production server | `php siro frankenphp:serve [--docker] [--port=80] [--docker-port=]` |
| `live` | Live-reload dev server with file watcher | `php siro live [--port=9090]` |
| `deploy` | Deploy application | `php siro deploy [env] [--init] [--list]` |
| `storage:link` | Create public/storage symlink | `php siro storage:link` |

### Examples

```bash
php siro serve --port=3000                # Dev server on port 3000
php siro frankenphp:serve --docker        # FrankenPHP in Docker
php siro live --port=9090                 # Hot-reload server
php siro deploy --init                    # First-time deploy setup
```

---

## system:* — System & Utility (29 commands)

| Command | Description | Usage |
|---|---|---|
| `benchmark` | Run performance benchmarks | `php siro benchmark [--iterations=N] [--warmup=N] [--json]` |
| `api:contract` | Verify OpenAPI against registered routes | `php siro api:contract [--spec=] [--path=] [--strict] [--coverage]` |
| `webhook:test` | Send a test webhook event | `php siro webhook:test <event> [--url=] [--secret=] [--payload=]` |
| `api:why` | Trace a specific request — middleware, SQL, timing, exception | `php siro api:why <METHOD> <path>` |
| `key:generate` | Generate JWT/APP secret key | `php siro key:generate [--force]` |
| `config:cache` | Cache configuration for faster boot | `php siro config:cache` |
| `config:clear` | Clear cached config and routes | `php siro config:clear` |
| `env:cache` | Cache environment variables | `php siro env:cache` |
| `optimize` | Optimize for production (cache everything) | `php siro optimize` |
| `env:check` | Validate environment configuration | `php siro env:check` |
| `env:switch` | Switch between environments | `php siro env:switch <env>` |
| `doctor` | Full system health check | `php siro doctor [--prod]` |
| `fix` | Watch file changes & auto-replay last test | `php siro fix` |
| `down` | Enable maintenance mode | `php siro down [--message="..."] [--retry=60] [--allow=ip1,ip2]` |
| `up` | Disable maintenance mode | `php siro up` |
| `route:list` | List all registered routes | `php siro route:list` |
| `route:cache` | Cache routes for faster boot | `php siro route:cache` |
| `route:search` | Search routes by keyword | `php siro route:search <keyword>` |
| `route:rules` | Show validation rules for all routes | `php siro route:rules` |
| `trace:list` | List recent request traces | `php siro trace:list [--limit=20]` |
| `rate:status` | Rate limiter dashboard | `php siro rate:status` |
| `replay` | Replay the last trace (or by ID), risk-aware | `php siro replay [trace_id] [--force] [--edit] [--diff] [--dry-run]` |
| `runtime` | Siro Runtime manager (install, switch, list) | `php siro runtime [action] [name]` |
| `db` | Database manager (init, start, stop) | `php siro db [action] [--mysql] [--mysql-official]` |
| `demo` | 30s debug workflow demo — test, fail, why, fix, trace | `php siro demo` |
| `tinker` | Interactive PHP playground in app context | `php siro tinker` |
| `mercure:subscribe` | Subscribe to Mercure topics and print events | `php siro mercure:subscribe <topic>` |
| `audit:verify` | Verify immutable audit trail integrity | `php siro audit:verify [--all]` |
| `audit:log` | Append a manual audit entry | `php siro audit:log <action> [--context=key=value]` |

### Examples

```bash
php siro key:generate                        # Generate 32-char base64 key
php siro benchmark --iterations=100 --json   # Benchmark with JSON output
php siro route:search user                   # Find all routes with "user"
php siro doctor --prod                       # Production readiness check
php siro down --message="Upgrading..." --retry=120 --allow=192.168.1.1
php siro up                                  # Bring site back online
php siro new my-project                      # Scaffold from skeleton
php siro test --suite=Security --coverage    # Run security tests with coverage
php siro fix                                 # Watch mode for TDD
```

### Aliases

| Alias | Expands to |
|---|---|
| `slow` | `log:slow` |
| `why` | `debug:last` |
| `traces` | `trace:list` |
| `t` | `api:test` |
| `tink` | `tinker` |
| `make:docs` | `make:openapi --with-swagger` |
| `open:postman` | `log:export --postman` |

```bash
php siro why        # Same as: php siro debug:last
php siro traces     # Same as: php siro trace:list
php siro slow       # Same as: php siro log:slow
php siro t GET /api/users  # Same as: php siro api:test GET /api/users
```

---

## Typo Handling (Levenshtein Suggestions)

When a command is not found, the CLI computes the [Levenshtein distance](https://en.wikipedia.org/wiki/Levenshtein_distance) against all registered commands (distance <= 3) and suggests up to 5 alternatives:

```bash
$ php siro mak:controler
Unknown command: mak:controler
Did you mean?
  make:controller
  make:listener
  make:middleware
```

Prefix matching also triggers suggestions (e.g. `migrat` → `migrate`, `migrate:rollback`, `migrate:status`).

---

## Complete Command Table

| # | Command | Category |
|---|---|---|
| 1 | `make:auth` | make:* |
| 2 | `make:controller` | make:* |
| 3 | `make:model` | make:* |
| 4 | `make:migration` | make:* |
| 5 | `make:queue-table` | make:* |
| 6 | `make:resource` | make:* |
| 7 | `make:seeder` | make:* |
| 8 | `make:crud` | make:* |
| 9 | `make:test` | make:* |
| 10 | `make:job` | make:* |
| 11 | `make:mail` | make:* |
| 12 | `make:event` | make:* |
| 13 | `make:lang` | make:* |
| 14 | `make:factory` | make:* |
| 15 | `make:openapi` | make:* |
| 16 | `make:postman` | make:* |
| 17 | `make:service` | make:* |
| 18 | `make:repository` | make:* |
| 19 | `make:middleware` | make:* |
| 20 | `make:listener` | make:* |
| 21 | `make:webhook` | make:* |
| 22 | `make:request` | make:* |
| 23 | `make:rule` | make:* |
| 24 | `make:observer` | make:* |
| 25 | `make:idempotency-table` | make:* |
| 26 | `make:apikey-table` | make:* |
| 27 | `make:apikey` | make:* |
| 28 | `make:oauth-client` | make:* |
| 29 | `make:oauth-keys` | make:* |
| 30 | `new` | new |
| 31 | `new:project` | new |
| 32 | `migrate` | db:* |
| 33 | `migrate:fresh` | db:* |
| 34 | `migrate:rollback` | db:* |
| 35 | `migrate:status` | db:* |
| 36 | `migrate:reset` | db:* |
| 37 | `migrate:refresh` | db:* |
| 38 | `db:seed` | db:* |
| 39 | `db:show` | db:* |
| 40 | `db:health` | db:* |
| 41 | `db:check` | db:* |
| 42 | `db:stats` | db:* |
| 43 | `db:optimize` | db:* |
| 44 | `db:backup` | db:* |
| 45 | `db:restore` | db:* |
| 46 | `db:explain` | db:* |
| 47 | `db:benchmark` | db:* |
| 48 | `db:why` | db:* |
| 49 | `log:replay` | log:* |
| 50 | `log:trace` | log:* |
| 51 | `log:export` | log:* |
| 52 | `log:cleanup` | log:* |
| 53 | `log:slow` | log:* |
| 54 | `log:tail` | log:* |
| 55 | `log:stats` | log:* |
| 56 | `log:top` | log:* |
| 57 | `debug:last` | log:* |
| 58 | `debug:health` | log:* |
| 59 | `test:regression` | test |
| 60 | `test:run` | test |
| 61 | `api:test` | test |
| 62 | `test` | test |
| 63 | `queue:work` | queue:* |
| 64 | `queue:retry` | queue:* |
| 65 | `queue:flush` | queue:* |
| 66 | `queue:status` | queue:* |
| 67 | `schedule:run` | queue:* |
| 68 | `schedule:list` | queue:* |
| 69 | `serve` | server:* |
| 70 | `frankenphp:serve` | server:* |
| 71 | `live` | server:* |
| 72 | `deploy` | server:* |
| 73 | `storage:link` | server:* |
| 74 | `benchmark` | system:* |
| 75 | `api:contract` | system:* |
| 76 | `webhook:test` | system:* |
| 77 | `api:why` | system:* |
| 78 | `key:generate` | system:* |
| 79 | `config:cache` | system:* |
| 80 | `config:clear` | system:* |
| 81 | `env:cache` | system:* |
| 82 | `optimize` | system:* |
| 83 | `env:check` | system:* |
| 84 | `env:switch` | system:* |
| 85 | `doctor` | system:* |
| 86 | `fix` | system:* |
| 87 | `down` | system:* |
| 88 | `up` | system:* |
| 89 | `route:list` | system:* |
| 90 | `route:cache` | system:* |
| 91 | `route:search` | system:* |
| 92 | `route:rules` | system:* |
| 93 | `trace:list` | system:* |
| 94 | `rate:status` | system:* |
| 95 | `replay` | system:* |
| 96 | `runtime` | system:* |
| 97 | `db` | system:* |
| 98 | `demo` | system:* |
| 99 | `tinker` | system:* |
| 100 | `mercure:subscribe` | system:* |
| 101 | `audit:verify` | system:* |
| 102 | `audit:log` | system:* |

---

> See [ARCHITECTURE.md](ARCHITECTURE.md) for framework internals and [SECURITY.md](SECURITY.md) for security features.
