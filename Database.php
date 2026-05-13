<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use Siro\Core\DB\DatabaseInterface;
use Siro\Core\DB\DatabaseInstance;
use Siro\Core\DB\QueryBuilder;

final class Database
{
    private static ?DatabaseInterface $instance = null;

    public static function getInstance(): DatabaseInterface
    {
        if (self::$instance === null) {
            $container = Container::getInstance();
            if ($container->has(DatabaseInterface::class)) {
                self::$instance = $container->make(DatabaseInterface::class);
            } else {
                self::$instance = new DatabaseInstance();
            }
        }
        return self::$instance;
    }

    public static function setInstance(?DatabaseInterface $instance): void
    {
        self::$instance = $instance;
    }

    public static function configure(array $config, string $name = 'default'): void
    {
        self::getInstance()->configure($config, $name);
    }

    public static function default(string $name): void
    {
        self::getInstance()->default($name);
    }

    public static function connection(?string $name = null): PDO
    {
        return self::getInstance()->connection($name);
    }

    public static function purge(?string $name = null): void
    {
        self::getInstance()->purge($name);
    }

    public static function purgeAll(): void
    {
        self::getInstance()->purgeAll();
    }

    public static function connections(): array
    {
        return self::getInstance()->connections();
    }

    public static function getCapturedQueries(): array
    {
        return self::getInstance()->getCapturedQueries();
    }

    public static function resetCapturedQueries(): void
    {
        self::getInstance()->resetCapturedQueries();
    }

    public static function select(string $sql, array $params = [], ?string $connection = null): array
    {
        return self::getInstance()->select($sql, $params, $connection);
    }

    public static function first(string $sql, array $params = [], ?string $connection = null): ?array
    {
        return self::getInstance()->first($sql, $params, $connection);
    }

    public static function execute(string $sql, array $params = [], ?string $connection = null): int
    {
        return self::getInstance()->execute($sql, $params, $connection);
    }

    public static function cache(int $ttl = 60): DatabaseInterface
    {
        return self::getInstance()->cache($ttl);
    }

    public static function table(string $table, ?string $connection = null): QueryBuilder
    {
        return self::getInstance()->table($table, $connection);
    }

    public static function transaction(callable $callback, ?string $connection = null): mixed
    {
        return self::getInstance()->transaction($callback, $connection);
    }

    public static function selectCached(string $sql, array $params, int $ttl, string $cachePrefix = 'qb:default:', ?string $connection = null): array
    {
        return self::getInstance()->selectCached($sql, $params, $ttl, $cachePrefix, $connection);
    }
}
