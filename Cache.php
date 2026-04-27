<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\Cache\Drivers\FileDriver;
use Siro\Core\Cache\Drivers\RedisDriver;

final class Cache
{
    private static RedisDriver|FileDriver|null $driver = null;
    private static string $prefix = 'siro:';
    private static int $defaultTtl = 60;
    private static bool $requestHadCacheHit = false;

    public static function boot(string $basePath): void
    {
        self::$prefix = (string) Env::get('CACHE_PREFIX', 'siro:');
        self::$defaultTtl = max(1, (int) Env::get('CACHE_TTL', '60'));

        $driver = strtolower((string) Env::get('CACHE_DRIVER', 'file'));
        $cachePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

        if ($driver === 'redis') {
            $redisDriver = self::createRedisDriver();
            if ($redisDriver instanceof RedisDriver) {
                self::$driver = $redisDriver;
                return;
            }
        }

        self::$driver = new FileDriver($cachePath);
    }

    public static function resetRequestState(): void
    {
        self::$requestHadCacheHit = false;
    }

    public static function requestStatus(): string
    {
        return self::$requestHadCacheHit ? 'HIT' : 'MISS';
    }

    public static function get(string $key): mixed
    {
        $value = self::driver()->get(self::normalizeKey($key));
        if ($value !== null) {
            self::$requestHadCacheHit = true;
        }

        return $value;
    }

    public static function set(string $key, mixed $value, int $ttl = 60): bool
    {
        $ttl = $ttl > 0 ? $ttl : self::$defaultTtl;
        return self::driver()->set(self::normalizeKey($key), $value, $ttl);
    }

    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = self::get($key);
        if ($cached !== null || self::has($key)) {
            return $cached;
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    public static function forget(string $key): bool
    {
        return self::driver()->forget(self::normalizeKey($key));
    }

    public static function has(string $key): bool
    {
        return self::driver()->has(self::normalizeKey($key));
    }

    public static function flush(string $prefix = ''): int
    {
        if ($prefix === '') {
            return self::driver()->flush();
        }

        return self::driver()->flush(self::normalizeKey($prefix));
    }

    public static function flushQueryBuilderTable(string $table): int
    {
        $table = strtolower(trim($table));
        if ($table === '') {
            return 0;
        }

        return self::flush('qb:' . $table . ':');
    }

    private static function normalizeKey(string $key): string
    {
        return self::$prefix . $key;
    }

    private static function driver(): RedisDriver|FileDriver
    {
        if (self::$driver instanceof RedisDriver || self::$driver instanceof FileDriver) {
            return self::$driver;
        }

        self::$driver = new FileDriver(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');
        return self::$driver;
    }

    private static function createRedisDriver(): ?RedisDriver
    {
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

            return new RedisDriver($redis);
        } catch (\Throwable) {
            return null;
        }
    }
}
