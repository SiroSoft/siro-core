<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\Cache\CacheInterface;
use Siro\Core\Cache\CacheInstance;

final class Cache
{
    private static ?CacheInterface $instance = null;

    public static function getInstance(): CacheInterface
    {
        if (self::$instance === null) {
            $container = Container::getInstance();
            if ($container->has(CacheInterface::class)) {
                $instance = $container->make(CacheInterface::class);
                self::$instance = $instance instanceof CacheInterface ? $instance : new CacheInstance();
            } else {
                self::$instance = new CacheInstance();
            }
        }
        return self::$instance;
    }

    public static function setInstance(?CacheInterface $instance): void
    {
        self::$instance = $instance;
    }

    public static function boot(string $basePath): void { self::getInstance()->boot($basePath); }
    public static function reset(): void { self::getInstance()->reset(); }
    public static function resetRequestState(): void { self::getInstance()->resetRequestState(); }
    /** @return array<string, mixed> */
    public static function requestStatus(): array { $result = self::getInstance()->requestStatus(); return $result; }
    public static function get(string $key): mixed { return self::getInstance()->get($key); }
    public static function set(string $key, mixed $value, int $ttl = 60): bool { return self::getInstance()->set($key, $value, $ttl); }
    public static function remember(string $key, int $ttl, callable $callback): mixed { return self::getInstance()->remember($key, $ttl, $callback); }
    public static function forget(string $key): bool { return self::getInstance()->forget($key); }
    public static function has(string $key): bool { return self::getInstance()->has($key); }
    public static function flush(string $prefix = ''): int { return self::getInstance()->flush($prefix); }
    public static function flushQueryBuilderTable(string $table): int { return self::getInstance()->flushQueryBuilderTable($table); }
}
