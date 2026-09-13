# Release 1.0.8 Plan

## Scope

Release the two remaining ORM/schema reliability improvements and make ERP
production installs reproducible from `composer.lock`.

## C6: Foreign-key abstraction

Add `Blueprint::dropForeignByColumn(string $column)`. It resolves the physical
constraint name from the active database metadata on MySQL/MariaDB and
PostgreSQL, then compiles the correct `ALTER TABLE` statement. SQLite is a
no-op because SQLite does not support dropping a foreign key with ALTER TABLE.
`dropForeign(string $name)` remains available for explicit names.

Acceptance:

- a migration using the column name drops a constraint named by the database;
- MySQL and PostgreSQL metadata queries are parameterized;
- unknown columns produce a clear exception;
- existing explicit-name behavior remains unchanged.

## C7: Eager loading and N+1 detection

Use one eager-load path for `get()`, `paginate()`, and `cursorPaginate()` so a
model `$with` declaration cannot be bypassed by pagination. Add
`Database::getNPlusOneQueries()` over captured queries. It normalizes literal
values and reports repeated SELECT shapes, count, and total time. The detector
is diagnostic only and never changes runtime behavior.

Acceptance:

- `$with` loads relations for all three result APIs;
- a batched `with()` relation uses a bounded query count;
- captured repeated SELECTs are reported with a configurable threshold;
- tests cover empty results, multiple parents, and no false positives for
  different SQL shapes.

## ERP Composer release path

- Change ERP's core constraint to `^1.0.8`.
- Publish/tag core `v1.0.8` before updating the lock.
- Run `composer update sirosoft/core --with-dependencies` locally and commit
  `composer.lock`.
- Build the backend artifact with `composer install --no-dev --prefer-dist
  --no-interaction --optimize-autoloader` from the lock file.
- Deploy the artifact and verify the installed package version inside the
  container before restart. No manual vendor file copying is accepted.
- Run health, contract, PHPUnit, and production smoke checks after restart.

## Release gates

Core PHPUnit, PHPStan max, ERP PHPUnit, ERP PHPStan, Composer validate/lock
check, production health, and production smoke must all pass. Tagging is the
last action after the gates, not the first.
