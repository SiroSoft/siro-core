# CLI Reference

All 70 SiroPHP CLI commands, grouped by category.

> Run `php siro list` to see all commands or `php siro <command> --help` for details.

---

## Getting Help

```bash
php siro                    # Core workflow overview
php siro list               # List all 70 commands grouped by category
php siro <command> --help   # Detailed help for a specific command
php siro -h                 # Shorthand help overview
php siro --version          # Show version (0.28.1)
```

---

## make:* — Code Generators (23 commands)

| Command | Description | Usage |
|---|---|---|
| `make:auth` | Generate full auth system (login, register, middleware, routes, JWT config) | `php siro make:auth` |
| `make:controller` | Generate controller class | `php siro make:controller <name>` |
| `make:model` | Generate Eloquent-style model | `php siro make:model <name>` |
| `make:migration` | Generate database migration | `php siro make:migration <name>` |
| `make:crud` | Full CRUD scaffolding with controller, model, migration, routes | `php siro make:crud <name> [--simple] [--seed] [--force]` |
| `make:service` | Generate service class | `php siro make:service <name>` |
| `make:repository` | Generate repository class | `php siro make:repository <name>` |
| `make:resource` | Generate API resource transformer | `php siro make:resource <name>` |
| `make:middleware` | Generate middleware class | `php siro make:middleware <name>` |
| `make:listener` | Generate event listener | `php siro make:listener <name>` |
| `make:event` | Generate event class | `php siro make:event <name>` |
| `make:job` | Generate queue job class | `php siro make:job <name>` |
| `make:mail` | Generate mail class | `php siro make:mail <name>` |
| `make:test` | Generate PHPUnit test file | `php siro make:test <name>` |
| `make:factory` | Generate model factory | `php siro make:factory <name>` |
| `make:openapi` | Generate OpenAPI 3.0 spec from route annotations | `php siro make:openapi [--with-swagger] [--tag=TAG] [--flow=auth\|crud]` |
| `make:postman` | Generate Postman collection JSON | `php siro make:postman [--flow=crud]` |
| `make:lang` | Generate language/localization file | `php siro make:lang <locale> <file>` |
| `make:seeder` | Generate database seeder | `php siro make:seeder <name>` |
| `make:queue-table` | Create migrations for queue jobs table | `php siro make:queue-table` |
| `make:idempotency-table` | Create migration for idempotency table | `php siro make:idempotency-table` |
| `make:apikey-table` | Create migration for API keys table | `php siro make:apikey-table` |
| `make:apikey` | Generate a new API key with scopes | `php siro make:apikey <name> [scopes] [expires_days]` |

### Examples

```bash
php siro make:crud products                  # Full CRUD with controller, model, migration
php siro make:crud orders --simple           # CRUD without relations
php siro make:crud orders --seed             # CRUD + seeder
php siro make:auth                           # Generate login/register with JWT
php siro make:openapi --with-swagger         # Generate Swagger UI docs
php siro make:apikey "External App" read,write 365
```

---

## db:* — Database (6 commands)

| Command | Description | Usage |
|---|---|---|
| `migrate` | Run all pending migrations | `php siro migrate` |
| `migrate:rollback` | Rollback the last batch of migrations | `php siro migrate:rollback [--step=N]` |
| `migrate:status` | Show migration status (pending/ran) | `php siro migrate:status [--pending]` |
| `migrate:fresh` | Drop all tables and re-run all migrations | `php siro migrate:fresh [--seed]` |
| `db:seed` | Run all database seeders | `php siro db:seed` |
| `db:show` | Inspect table schema or data | `php siro db:show <table> [--schema]` |

### Examples

```bash
php siro migrate                          # Run all pending migrations
php siro migrate:rollback --step=2        # Rollback last 2 batches
php siro migrate:status                   # Check which migrations ran
php siro migrate:status --pending         # Show only pending migrations
php siro migrate:fresh                    # Drop all tables + re-migrate
php siro migrate:fresh --seed             # Drop + migrate + seed
php siro db:seed                          # Seed the database
php siro db:show users                    # Show table data
php siro db:show users --schema           # Show table columns & types
```

---

## cache:* — Cache & Config (3 commands)

| Command | Description | Usage |
|---|---|---|
| `config:cache` | Cache configuration for faster boot | `php siro config:cache` |
| `config:clear` | Clear cached config and routes | `php siro config:clear` |
| `env:cache` | Cache environment variables | `php siro env:cache` |

---

## log:* — Logs & Debugging (9 commands)

| Command | Description | Usage |
|---|---|---|
| `log:tail` | Tail log files in real-time | `php siro log:tail [--type=request\|error\|slow] [--lines=N] [--follow\|-f]` |
| `log:trace` | View detailed request trace | `php siro log:trace [<id>] [--status=500] [--limit=N] [--full]` |
| `log:replay` | Replay a previous request from trace | `php siro log:replay <trace_id> [--force] [--set key=val]` |
| `log:export` | Export trace in JSON, CSV, or Postman format | `php siro log:export <trace_id> --postman` |
| `log:cleanup` | Clean old trace files | `php siro log:cleanup [--days=N] [--dry-run]` |
| `log:slow` | Show slow requests | `php siro log:slow [--limit=N] [--min=MS]` |
| `log:stats` | Request statistics with ASCII charts | `php siro log:stats [--days=N]` |
| `log:top` | Top slowest APIs by total cumulative time | `php siro log:top [--limit=N] [--min=MS]` |
| `debug:last` | Show why the last request failed | `php siro debug:last` |

### Examples

```bash
php siro log:tail --type=error -f          # Follow error log in real-time
php siro log:trace --status=500            # List all failed (500) traces
php siro log:replay abc123 --force         # Replay a trace in dry-run mode
php siro log:export abc123 --postman       # Export as Postman collection
php siro log:cleanup --days=7 --dry-run    # Preview cleanup without deleting
php siro log:slow --min=500                # Requests slower than 500ms
php siro log:stats --days=30               # Stats for last 30 days
```

---

## queue:* — Queue (4 commands)

| Command | Description | Usage |
|---|---|---|
| `queue:work` | Process queue jobs | `php siro queue:work [--daemon]` |
| `queue:retry` | Retry failed jobs | `php siro queue:retry <id\|all>` |
| `queue:flush` | Clear all failed jobs | `php siro queue:flush` |
| `queue:status` | Queue statistics dashboard | `php siro queue:status` |

### Examples

```bash
php siro queue:work --daemon              # Process jobs continuously
php siro queue:retry all                  # Retry every failed job
php siro queue:status                     # Show pending/failed counts
```

---

## server:* — Server & Deploy (4 commands)

| Command | Description | Usage |
|---|---|---|
| `serve` | Start PHP built-in development server | `php siro serve [--port=8080]` |
| `frankenphp:serve` | Start FrankenPHP production server | `php siro frankenphp:serve [--docker] [--port=80]` |
| `live` | Live-reload dev server with file watcher | `php siro live [--port=9090]` |
| `deploy` | Deploy application | `php siro deploy [--init]` |

### Examples

```bash
php siro serve --port=3000                # Dev server on port 3000
php siro frankenphp:serve --docker        # FrankenPHP in Docker
php siro live --port=9090                 # Hot-reload server
php siro deploy --init                    # First-time deploy setup
```

---

## system:* — System & Utility (22 commands)

| Command | Description | Usage |
|---|---|---|
| `key:generate` | Generate JWT/APP secret key | `php siro key:generate` |
| `benchmark` | Run performance benchmarks | `php siro benchmark [--iterations=N] [--json]` |
| `route:list` | List all registered routes | `php siro route:list` |
| `route:search` | Search routes by keyword | `php siro route:search <keyword>` |
| `route:rules` | Show validation rules for all routes | `php siro route:rules` |
| `trace:list` | List recent request traces | `php siro trace:list [--limit=20]` |
| `rate:status` | Rate limiter dashboard | `php siro rate:status` |
| `replay` | Replay the last trace (or by ID) | `php siro replay [trace_id] [--edit] [--diff]` |
| `test` | Run PHPUnit tests with filter/suite/coverage | `php siro test [--filter=name] [--suite=Unit] [--coverage]` |
| `schedule:run` | Run all scheduled tasks | `php siro schedule:run` |
| `debug:health` | Check debug/trace system health | `php siro debug:health` |
| `storage:link` | Create public/storage symlink | `php siro storage:link` |
| `optimize` | Optimize for production (cache everything) | `php siro optimize` |
| `doctor` | Full system health check | `php siro doctor [--prod]` |
| `fix` | Watch file changes & auto-replay last test | `php siro fix` |
| `config:cache` | Cache configuration files | `php siro config:cache` |
| `config:clear` | Clear all cached config/routes | `php siro config:clear` |
| `env:cache` | Cache environment variables | `php siro env:cache` |
| `env:check` | Validate environment configuration | `php siro env:check` |
| `env:switch` | Switch between environments | `php siro env:switch <env>` |
| `down` | Enable maintenance mode | `php siro down [--message="..."] [--retry=60] [--allow=ip1,ip2]` |
| `up` | Disable maintenance mode | `php siro up` |
| `new` | Create a new project from skeleton | `php siro new <name>` |

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
| 5 | `make:crud` | make:* |
| 6 | `make:service` | make:* |
| 7 | `make:repository` | make:* |
| 8 | `make:resource` | make:* |
| 9 | `make:middleware` | make:* |
| 10 | `make:listener` | make:* |
| 11 | `make:event` | make:* |
| 12 | `make:job` | make:* |
| 13 | `make:mail` | make:* |
| 14 | `make:test` | make:* |
| 15 | `make:factory` | make:* |
| 16 | `make:openapi` | make:* |
| 17 | `make:postman` | make:* |
| 18 | `make:lang` | make:* |
| 19 | `make:seeder` | make:* |
| 20 | `make:queue-table` | make:* |
| 21 | `make:idempotency-table` | make:* |
| 22 | `make:apikey-table` | make:* |
| 23 | `make:apikey` | make:* |
| 24 | `migrate` | db:* |
| 25 | `migrate:rollback` | db:* |
| 26 | `migrate:status` | db:* |
| 27 | `db:seed` | db:* |
| 28 | `db:show` | db:* |
| 29 | `config:cache` | cache:* |
| 30 | `config:clear` | cache:* |
| 31 | `env:cache` | cache:* |
| 32 | `log:tail` | log:* |
| 33 | `log:trace` | log:* |
| 34 | `log:replay` | log:* |
| 35 | `log:export` | log:* |
| 36 | `log:cleanup` | log:* |
| 37 | `log:slow` | log:* |
| 38 | `log:stats` | log:* |
| 39 | `log:top` | log:* |
| 40 | `debug:last` | log:* |
| 41 | `debug:health` | log:* |
| 42 | `queue:work` | queue:* |
| 43 | `queue:retry` | queue:* |
| 44 | `queue:flush` | queue:* |
| 45 | `queue:status` | queue:* |
| 46 | `serve` | server:* |
| 47 | `frankenphp:serve` | server:* |
| 48 | `live` | server:* |
| 49 | `deploy` | server:* |
| 50 | `key:generate` | system:* |
| 51 | `benchmark` | system:* |
| 52 | `route:list` | system:* |
| 53 | `route:search` | system:* |
| 54 | `route:rules` | system:* |
| 55 | `trace:list` | system:* |
| 56 | `rate:status` | system:* |
| 57 | `replay` | system:* |
| 58 | `test` | system:* |
| 59 | `schedule:run` | system:* |
| 60 | `storage:link` | system:* |
| 61 | `optimize` | system:* |
| 62 | `doctor` | system:* |
| 63 | `fix` | system:* |
| 64 | `env:check` | system:* |
| 65 | `env:switch` | system:* |
| 66 | `down` | system:* |
| 67 | `up` | system:* |
| 68 | `new` | system:* |
| 69 | `api:test` | system:* |
| 70 | `test:run` | system:* |

---

> See [ARCHITECTURE.md](ARCHITECTURE.md) for framework internals and [SECURITY.md](SECURITY.md) for security features.
