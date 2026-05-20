# Database — Query Builder & Connection Manager

The `Database` class provides a static facade over a `DatabaseInterface` instance resolved through Siro's service container. At boot, `App::boot()` registers a `DatabaseInstance` singleton under `DatabaseInterface::class`. You can override it by binding your own implementation to the container.

The `DB` class is pure syntactic sugar — `DB::table('x')` simply calls `Database::table('x')`.

---

## Configuration

Configuration is loaded from `config/database.php` (returning an array) at boot:

```php
// config/database.php
return [
    'driver'               => 'sqlite',
    'database'             => ':memory:',
    'slow_query_threshold' => 500,       // ms — logs slow queries via Logger
];
```

Environment variables in `.env` override at runtime through `Env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_database
DB_USERNAME=root
DB_PASSWORD=secret
DB_CHARSET=utf8mb4
```

Supported drivers: `mysql`, `pgsql` / `postgres` / `postgresql`, `sqlite`.

**Multiple named connections:**

```php
Database::configure([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'database' => 'primary_db',
    'username' => 'root',
    'password' => 'secret',
], 'primary');

Database::configure([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'database' => 'analytics_db',
    'username' => 'reader',
    'password' => 'secret',
], 'analytics');

Database::default('primary');
```

---

## Getting a Query Builder

```php
use Siro\Core\DB;
use Siro\Core\Database;

// Syntactic sugar — both produce the same QueryBuilder
$query = DB::table('users');
$query = Database::table('users');

// On a named connection
$query = Database::table('orders', 'analytics');
$query = (new QueryBuilder('orders'))->connection('analytics');
```

---

## SELECT

```php
$users = DB::table('users')->get();
// SELECT * FROM `users`

$users = DB::table('users')->select('id', 'name', 'email')->get();
// SELECT `id`, `name`, `email` FROM `users`

$user = DB::table('users')->where('id', 1)->first();
// SELECT * FROM `users` WHERE `id` = :w_0  LIMIT 1
// Returns single row or null

$email = DB::table('users')->where('id', 1)->value('email');
// Returns scalar value directly

$names = DB::table('users')->pluck('name', 'id');
// ['1' => 'Alice', '2' => 'Bob', ...]

$sql = DB::table('users')->where('id', 1)->toSql();
// Debug: get the SQL string without executing
```

---

## INSERT

```php
// Returns last inserted ID (or affected row count)
$id = DB::table('users')->insert([
    'name'  => 'Alice',
    'email' => 'alice@example.com',
]);

// Alias
$id = DB::table('users')->insertGetId([...]);

// Bulk insert — returns inserted row count
$count = DB::table('users')->insertMany([
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob',   'email' => 'bob@example.com'],
]);
```

---

## UPDATE

```php
$affected = DB::table('users')
    ->where('id', 1)
    ->update(['name' => 'Alice Smith']);

// Bulk update by IDs
$affected = DB::table('users')
    ->updateWhereIn([1, 2, 3], ['status' => 'active']);
```

---

## DELETE

```php
$deleted = DB::table('users')
    ->where('id', 1)
    ->delete();

// Bulk delete by IDs
$deleted = DB::table('users')
    ->deleteWhereIn([1, 2, 3]);
```

---

## WHERE Clauses

### Basic WHERE

The third argument is optional. When omitted, `=` is assumed.

```php
DB::table('users')->where('id', 1);
// WHERE `id` = :w_0

DB::table('users')->where('age', '>', 18);
// WHERE `age` > :w_0

DB::table('users')->where('name', 'LIKE', '%Alice%');
// WHERE `name` LIKE :w_0
```

### OR WHERE

```php
DB::table('users')
    ->where('role', 'admin')
    ->orWhere('role', 'superadmin');
// WHERE `role` = :w_0 OR `role` = :w_1
```

### WHERE IN / NOT IN

```php
DB::table('users')->whereIn('id', [1, 2, 3]);
// WHERE `id` IN (:wi_0_0, :wi_0_1, :wi_0_2)

DB::table('users')->whereNotIn('id', [1, 2, 3]);

DB::table('users')->orWhereIn('id', [1, 2, 3]);
```

### WHERE BETWEEN / NOT BETWEEN

```php
DB::table('users')->whereBetween('age', [18, 65]);
// WHERE `age` BETWEEN :wb_min_0 AND :wb_max_0

DB::table('users')->whereNotBetween('age', [18, 65]);
```

### WHERE NULL / NOT NULL

```php
DB::table('users')->whereNull('deleted_at');
DB::table('users')->whereNotNull('email_verified_at');
DB::table('users')->orWhereNull('deleted_at');
DB::table('users')->orWhereNotNull('email_verified_at');
```

### Raw WHERE

```php
DB::table('users')->whereRaw(
    'YEAR(created_at) = :year AND MONTH(created_at) = :month',
    ['year' => 2024, 'month' => 1]
);
```

---

## JOINs

```php
DB::table('users')
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->get();
// INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id`

DB::table('users')
    ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
    ->get();
// LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id`
```

Cross joins can be done with `join` and a tautology:

```php
DB::table('users')
    ->join('departments', '1', '=', '1');
// INNER JOIN `departments` ON 1 = 1
```

---

## ORDER BY, GROUP BY, HAVING

```php
DB::table('users')
    ->orderBy('name', 'asc')
    ->orderBy('created_at', 'desc')
    ->get();

DB::table('users')
    ->groupBy('role')
    ->having('role', 'admin')
    ->get();

DB::table('users')
    ->groupBy('role')
    ->having('COUNT(*)', '>', 10)
    ->orHaving('COUNT(*)', '=', 0)
    ->get();

// Random order (supports MySQL seed)
DB::table('users')->inRandomOrder()->get();
DB::table('users')->inRandomOrder(42)->get();  // MySQL only
```

### Raw GROUP BY / HAVING (v0.28)

Sử dụng `groupByRaw()` khi cần SQL function trong GROUP BY:

```php
DB::table('orders')
    ->selectRaw('YEAR(created_at) AS year, COUNT(*) AS total')
    ->groupByRaw('YEAR(created_at)')
    ->get();
```

Sử dụng `havingRaw()` cho HAVING clause với raw expression:

```php
DB::table('orders')
    ->groupBy('status')
    ->havingRaw('COUNT(*) > ?', [10])
    ->get();
```

Sử dụng `DB::raw()` để tạo raw expression trong bất kỳ clause nào:

```php
DB::table('users')
    ->groupBy(DB::raw('YEAR(created_at)'))
    ->orderBy(DB::raw('MAX(created_at)'), 'desc')
    ->get();
```

> **Note:** `groupBy()` quotes identifiers automatically. For SQL functions, use `groupByRaw()` or `DB::raw()`.

---

## Migrations

### Creating a Migration

```bash
php siro make:migration create_products_table
# → database/migrations/2026_05_19_100000_create_products_table.php
```

File naming format: `Y_m_d_His_description.php` (standardized since v0.28.1).

### Writing a Migration

```php
// database/migrations/2026_05_19_100000_create_products_table.php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->timestamps();
    $table->softDeletes();
});
```

### Migration Tracking

Siro uses a `migrations` table to track which migrations have been applied:

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | PRIMARY KEY |
| `migration` | VARCHAR(255) UNIQUE | Migration filename |
| `batch` | INT | Batch number (increments each migrate run) |
| `created_at` | TIMESTAMP | When the migration was applied |

- **Rollback** removes the migration record and runs `down()`.
- **File rename**: If you rename a migration file after it's been applied, the `migrations` table still stores the old name. `migrate:status` will show both the pending new name and the applied old name. To fix: `php siro migrate:rollback --step=1` or update the record manually.
- **`migrate:fresh`** (v0.28.1): Drops all tables and re-runs all migrations from scratch.

### Commands

```bash
php siro migrate                    # Run pending migrations
php siro migrate:rollback --step=2  # Rollback 2 batches
php siro migrate:status             # Show all migrations
php siro migrate:status --pending   # Show only pending (v0.28.1)
php siro migrate:fresh              # Drop + re-migrate (v0.28.1)
php siro migrate:fresh --seed       # Drop + migrate + seed
```

### Blueprint Helpers

| Method | Description |
|---|---|
| `$table->id()` | Auto-increment BIGINT primary key |
| `$table->foreignId('user_id')` | Create string(36) column, use with `constrained()` (v0.28.1) |
| `$table->string('name', 100)` | VARCHAR column |
| `$table->integer('count')` | INT column |
| `$table->decimal('price', 10, 2)` | Decimal column |
| `$table->text('body')` | TEXT column |
| `$table->boolean('active')` | TINYINT(1) column |
| `$table->json('metadata')` | JSON column (requires MySQL 8.0+ or PostgreSQL) |
| `$table->timestamps()` | created_at + updated_at |
| `$table->softDeletes()` | deleted_at column |
| `$table->index('email')` | Index |
| `$table->unique('slug')` | Unique index |

---

## LIMIT, OFFSET, Pagination

```php
DB::table('users')->limit(10)->offset(20)->get();

// Offset-based pagination
$result = DB::table('users')
    ->where('active', 1)
    ->paginate(perPage: 15, page: 2);

// Returns:
// [
//   'data' => [...],
//   'meta' => [
//     'page'      => 2,
//     'per_page'  => 15,
//     'total'     => 100,
//     'last_page' => 7,
//   ],
// ]

// Cursor-based pagination (stable for large datasets)
$result = DB::table('users')
    ->where('active', 1)
    ->cursorPaginate(perPage: 15, cursor: null, order: 'asc');

// Returns:
// [
//   'data'        => [...],
//   'meta'        => [...],
//   'next_cursor' => ['id' => 42, 'created_at' => '2024-01-15 10:00:00'] | null,
// ]
```

### chunk()

Processes large results in batches without loading everything into memory:

```php
DB::table('users')->chunk(100, function (array $rows): void {
    foreach ($rows as $user) {
        // process $user
    }
});
```

---

## Aggregates

```php
$count = DB::table('users')->count();           // int
$sum   = DB::table('orders')->sum('total');     // float|int
$avg   = DB::table('orders')->avg('total');     // float|int
$min   = DB::table('products')->min('price');   // float|int
$max   = DB::table('products')->max('price');   // float|int

// Existence checks
$exists = DB::table('users')->where('email', 'x@y.com')->exists();
$missing = DB::table('users')->where('email', 'x@y.com')->doesntExist();
```

---

## Raw Queries

```php
use Siro\Core\Database;

// Select multiple rows
$rows = Database::select('SELECT * FROM users WHERE role = :role', ['role' => 'admin']);

// Select first row (or null)
$user = Database::first('SELECT * FROM users WHERE id = :id', ['id' => 1]);

// Execute INSERT / UPDATE / DELETE (returns affected row count)
$affected = Database::execute('UPDATE users SET active = 0 WHERE last_login < :date', [
    'date' => '2023-01-01',
]);
```

---

## Transactions

Transactions use **savepoints** for nested calls. Only the outermost `beginTransaction` commits; inner calls create savepoints.

```php
Database::transaction(function () {
    DB::table('users')->insert(['name' => 'Alice']);
    DB::table('accounts')->insert(['user_id' => 42, 'balance' => 100]);

    // Nested transaction — creates a savepoint
    Database::transaction(function () {
        DB::table('logs')->insert(['action' => 'created_user']);
    });
});
```

If the callback throws, the root transaction is rolled back. Savepoints are released on success, rolled back on failure.

---

## Query Caching

### Query Builder cache

Chain `->cache(ttl)` before a `get()` or `first()` call. Subsequent identical queries are served from cache. Cache is **automatically invalidated** on `insert`, `update`, `delete`, `insertMany`, `updateWhereIn`, and `deleteWhereIn`.

```php
$users = DB::table('users')
    ->where('active', 1)
    ->cache(60)  // cache for 60 seconds
    ->get();

$user = DB::table('users')
    ->where('id', 1)
    ->cache(300)
    ->first();
```

### Raw query cache

```php
$rows = Database::selectCached(
    'SELECT * FROM users WHERE role = :role',
    ['role' => 'admin'],
    ttl: 60,
    cachePrefix: 'my:prefix:'
);
```

---

## Multiple Connections

```php
// Configure at boot
Database::configure($dbConfig, 'mysql_primary');
Database::configure($analyticsConfig, 'mysql_analytics');

// Query builder on a specific connection
DB::table('orders', 'mysql_analytics')->get();
Database::table('orders', 'mysql_analytics')->get();

// Raw queries
Database::select('SELECT 1', [], 'mysql_analytics');

// Default connection
Database::default('mysql_primary');

// List all configured connection names
$names = Database::connections();

// Purge a connection (closes PDO)
Database::purge('mysql_analytics');
Database::purgeAll();
```

---

## Error Handling

All database operations throw `PDOException` or `RuntimeException` on failure. Wrap in try/catch:

```php
use PDOException;
use RuntimeException;

try {
    DB::table('users')->insert(['name' => 'Alice']);
} catch (PDOException $e) {
    // Constraint violation, connection error, etc.
    Logger::error($e);
} catch (RuntimeException $e) {
    // Configuration errors, unsupported driver, empty table name
}
```

---

## Query Logging & Debugging

```php
// Dump SQL and bindings without executing
DB::table('users')->where('id', 1)->dump();
// dd() dumps and exits

// Get all captured queries for the request
$queries = Database::getCapturedQueries();
// [
//   ['sql' => '...', 'bindings' => [...], 'time_ms' => 1.23, 'rows' => 5, 'connection' => 'default'],
// ]

// Slow queries (> slow_query_threshold ms) are automatically logged via Logger::error()
```

---

## Instance-Based DI

At runtime, `Database::getInstance()` first checks the container for a `DatabaseInterface` binding. If none exists, it falls back to `new DatabaseInstance()`.

Override the implementation:

```php
use Siro\Core\Container;
use Siro\Core\DB\DatabaseInterface;

$container = Container::getInstance();
$container->singleton(DatabaseInterface::class, fn () => new MyCustomDatabaseDriver());

// Or set it directly
Database::setInstance(new MyCustomDatabaseDriver());
```

This allows swapping implementations for testing (mock driver) or using a custom connection pool.
