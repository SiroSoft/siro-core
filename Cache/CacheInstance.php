<?php

declare(strict_types=1);

namespace Siro\Core\Cache;

use Siro\Core\Cache\Drivers\FileDriver;
use Siro\Core\Cache\Drivers\RedisDriver;
use Siro\Core\Env;

/**
 * Cache facade with file and Redis driver support.
 *
 * Provides get/set/remember/forget/flush operations with
 * prefix-based invalidation and query builder integration
 * for automatic cache busting on data mutations.
 *
 * @package Siro\Core
 */
final class CacheInstance implements CacheInterface
{
    private RedisDriver|FileDriver|null $driver = null;
    private string $prefix = 'siro:';
    private int $defaultTtl = 60;
    private bool $requestHadCacheHit = false;

    public function boot(string $basePath): void
    {
        $this->prefix = (string) Env::get('CACHE_PREFIX', 'siro:');
        $this->defaultTtl = max(1, (int) Env::get('CACHE_TTL', '60'));

        $driver = strtolower((string) Env::get('CACHE_DRIVER', 'file'));
        $cachePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

        if ($driver === 'redis') {
            $redisDriver = $this->createRedisDriver();
            if ($redisDriver instanceof RedisDriver) {
                $this->driver = $redisDriver;
                return;
            }
        }

        $this->driver = new FileDriver($cachePath);
    }

    public function reset(): void
    {
        $this->driver = null;
        $this->prefix = 'siro:';
        $this->defaultTtl = 60;
        $this->requestHadCacheHit = false;
    }

    public function resetRequestState(): void
    {
        $this->requestHadCacheHit = false;
    }

    /** @return array<string, string> */
    public function requestStatus(): array
    {
        return [
            'status' => $this->requestHadCacheHit ? 'HIT' : 'MISS',
        ];
    }

    public function get(string $key): mixed
    {
        $value = $this->driver()->get($this->normalizeKey($key));
        if ($value !== null) {
            $this->requestHadCacheHit = true;
        }

        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 60): bool
    {
        $ttl = $ttl >= 0 ? $ttl : $this->defaultTtl;
        return $this->driver()->set($this->normalizeKey($key), $value, $ttl);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function forget(string $key): bool
    {
        return $this->driver()->forget($this->normalizeKey($key));
    }

    public function has(string $key): bool
    {
        return $this->driver()->has($this->normalizeKey($key));
    }

    public function flush(string $prefix = ''): int
    {
        if ($prefix === '') {
            return $this->driver()->flush();
        }

        return $this->driver()->flush($this->normalizeKey($prefix));
    }

    public function flushQueryBuilderTable(string $table): int
    {
        $table = strtolower(trim($table));
        if ($table === '') {
            return 0;
        }

        return $this->flush('qb:' . $table . ':');
    }

    private function normalizeKey(string $key): string
    {
        return $this->prefix . $key;
    }

    private function driver(): RedisDriver|FileDriver
    {
        if ($this->driver instanceof RedisDriver || $this->driver instanceof FileDriver) {
            return $this->driver;
        }

        $this->driver = new FileDriver(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');
        return $this->driver;
    }

    public static function getRedisConnection(): ?\Redis
    {
        if (self::$sharedRedis !== null) {
            return self::$sharedRedis;
        }

        if (!class_exists(\Redis::class)) {
            return null;
        }

        try {
            $host = (string) Env::get('REDIS_HOST', '127.0.0.1');
            $port = (int) Env::get('REDIS_PORT', '6379');
            $timeout = (float) Env::get('REDIS_TIMEOUT', '0.2');
            $password = Env::get('REDIS_PASSWORD');
            $database = (int) Env::get('REDIS_DB', '0');

            $redis = new \Redis();
            $connected = $redis->connect($host, $port, $timeout);

            if (!$connected) {
                return null;
            }

            if (is_string($password) && $password !== '') {
                $redis->auth($password);
            }

            if ($database > 0) {
                $redis->select($database);
            }

            self::$sharedRedis = $redis;
            return self::$sharedRedis;
        } catch (\Throwable) {
            return null;
        }
    }

    private function createRedisDriver(): ?RedisDriver
    {
        $redis = self::getRedisConnection();
        if ($redis === null) {
            return null;
        }

        return new RedisDriver($redis);
    }

    private static ?\Redis $sharedRedis = null;
}
