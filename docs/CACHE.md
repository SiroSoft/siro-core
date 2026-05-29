---
title: C AC HE
description: SiroPHP C AC HE reference
sidebar_position: 2
sidebar_label: C AC HE
---

# Cache — Key-Value Store

The `Cache` class provides a static facade over a `CacheInterface` instance resolved through Siro's service container. At boot, `App::boot()` calls `Cache::boot($basePath)`, which reads environment configuration and initializes the appropriate driver (File or Redis).

---

## Configuration

Via `.env`:

```
CACHE_DRIVER=file          # "file" or "redis"
CACHE_TTL=60               # Default TTL in seconds
CACHE_PREFIX=siro:         # Prefix applied to all keys

REDIS_HOST=127.0.0.1       # Redis-specific
REDIS_PORT=6379
REDIS_TIMEOUT=0.2
REDIS_PASSWORD=
REDIS_DB=0
```

If `CACHE_DRIVER=redis` and the `\Redis` extension is available, a `RedisDriver` is used. Otherwise, it falls back to `FileDriver`.

---

## Basic Usage

```php
use Siro\Core\Cache;

// Store a value (default TTL from CACHE_TTL env, or 60)
Cache::set('user:42', ['name' => 'Alice'], 120);

// Retrieve
$user = Cache::get('user:42');  // null if missing or expired

// Check existence
if (Cache::has('user:42')) { ... }

// Remove a key
Cache::forget('user:42');
```

---

## remember()

Executes the callback only when the key is missing; stores and returns the result.

```php
$user = Cache::remember('user:42', 300, function () {
    return DB::table('users')->where('id', 42)->first();
});
```

---

## flush()

```php
// Flush everything (deletes ALL cache entries)
Cache::flush();

// Flush only keys starting with a prefix
Cache::flush('user:');
```

---

## Cache Prefixes

Every key is automatically prefixed with `CACHE_PREFIX` (default `siro:`). So `Cache::get('foo')` actually reads `siro:foo`.

The prefix is configurable per environment:

```
# .env (production)
CACHE_PREFIX=siro:prod:

# .env.testing
CACHE_PREFIX=siro:core:test:
```

---

## Query Builder Cache Integration

The Query Builder automatically uses the cache system. When you chain `->cache(ttl)`:

```php
$users = DB::table('users')
    ->where('active', 1)
    ->cache(60)
    ->get();
```

The cache key is `qb:<tablename>:<sha1 of SQL+bindings>`. The Query Builder's `insert`, `update`, `delete`, `insertMany`, `updateWhereIn`, and `deleteWhereIn` methods call `Cache::flushQueryBuilderTable($table)` to **automatically invalidate** cached results for that table.

You can manually flush a table's query cache:

```php
Cache::flushQueryBuilderTable('users');
```

---

## Drivers

### File Driver

Stores JSON-encoded cache entries in `storage/cache/`. Each key is a `.cache` file with an embedded TTL expiration. Uses `LOCK_EX` for concurrent write safety and SHA1-based filenames to avoid collisions.

```php
// storage/cache/<prefix_hash>.cache
// {"key":"siro:...","expires_at":1700000000,"value":{...}}
```

### Redis Driver

Uses the `\Redis` extension with `SETEX` for atomic set-with-expiry and `SCAN` for prefix-based flush. Values are JSON-encoded.

```php
// SETEX siro:user:42 60 {"value":{"name":"Alice"}}
```

---

## TTL and Expiration

TTL is specified in seconds. The default TTL is `CACHE_TTL` (60 if not set). When calling `set()` or `remember()`:

```php
Cache::set('key', $value, 0);    // Uses default TTL (CACHE_TTL)
Cache::set('key', $value, 60);   // 60 seconds
Cache::set('key', $value, 3600); // 1 hour
Cache::remember('key', 86400, fn () => ...); // 24 hours
```

The File driver deletes expired entries on read. The Redis driver relies on Redis' built-in key expiration via `SETEX`.

---

## Multiple Environments

Separate prefixes prevent key collisions across environments:

```
# .env (production)
CACHE_PREFIX=siro:prod:
CACHE_DRIVER=redis
REDIS_HOST=redis-prod.internal

# .env (staging)
CACHE_PREFIX=siro:staging:
CACHE_DRIVER=file

# .env.testing
CACHE_PREFIX=siro:test:
CACHE_DRIVER=file
```

---

## Error Handling

Cache operations return `null` for misses, `false` for failed writes:

```php
$value = Cache::get('missing_key');     // null
$ok    = Cache::set('key', $value, 60); // false if write fails
$ok    = Cache::forget('key');          // false if key doesn't exist
$count = Cache::flush();                // number of deleted entries
```

The Redis driver silently degrades to File if the connection fails. No exceptions are thrown from cache operations.

---

## Request-Level State

Track if any cache hit occurred during the current request:

```php
$status = Cache::requestStatus();
// ['status' => 'HIT'] or ['status' => 'MISS']

// Reset between requests
Cache::resetRequestState();
```

---

## Instance-Based DI

Override the cache implementation via the container:

```php
use Siro\Core\Container;
use Siro\Core\Cache\CacheInterface;

$container = Container::getInstance();
$container->singleton(CacheInterface::class, fn () => new MyCustomCacheDriver());

// Or set directly
Cache::setInstance(new MyCustomCacheDriver());
```
