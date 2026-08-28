<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use Siro\Core\DB\DatabaseInterface;
use Siro\Core\DB\DatabaseInstance;
use Siro\Core\DB\QueryBuilder;
use Siro\Core\DB\RawExpression;

final class Database
{
    private static ?DatabaseInterface $instance = null;

    public static function getInstance(): DatabaseInterface
    {
        if (self::$instance === null) {
            $container = Container::getInstance();
            if ($container->has(DatabaseInterface::class)) {
                $instance = $container->make(DatabaseInterface::class);
                self::$instance = $instance instanceof DatabaseInterface ? $instance : new DatabaseInstance();
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

    /** @param array<string, mixed> $config */
    public static function configure(array $config, string $name = 'default'): void
    {
        self::getInstance()->configure($config, $name);
    }

    public static function default(string $name): void
    {
        self::getInstance()->default($name);
    }

    public static function connection(?string $name = null, bool $write = false): PDO
    {
        return self::getInstance()->connection($name, $write);
    }

    public static function purge(?string $name = null): void
    {
        self::getInstance()->purge($name);
    }

    public static function purgeAll(): void
    {
        self::getInstance()->purgeAll();
    }

    public static function writeConnection(?string $name = null): PDO
    {
        return self::getInstance()->connection($name, true);
    }

    /** @return array<int, string> */
    public static function connections(): array
    {
        return self::getInstance()->connections();
    }

    /** @return array<int, array{sql:string,bindings:array<int|string,mixed>,time_ms:float,rows:int,connection:string}> */
    public static function getCapturedQueries(): array
    {
        return self::getInstance()->getCapturedQueries();
    }

    public static function resetCapturedQueries(): void
    {
        self::getInstance()->resetCapturedQueries();
    }

    public static function enableQueryCapture(bool $enabled = true): void
    {
        self::getInstance()->enableQueryCapture($enabled);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $params = [], ?string $connection = null): array
    {
        return self::getInstance()->select($sql, $params, $connection);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function first(string $sql, array $params = [], ?string $connection = null): ?array
    {
        return self::getInstance()->first($sql, $params, $connection);
    }

    /** @param array<int|string, mixed> $params */
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

    public static function raw(string $value): RawExpression
    {
        return new RawExpression($value);
    }

    public static function execStatement(string $sql, ?string $connection = null): int
    {
        return self::getInstance()->exec($sql, $connection);
    }

    public static function transaction(callable $callback, ?string $connection = null): mixed
    {
        return self::getInstance()->transaction($callback, $connection);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function selectCached(string $sql, array $params, int $ttl, string $cachePrefix = 'qb:default:', ?string $connection = null): array
    {
        return self::getInstance()->selectCached($sql, $params, $ttl, $cachePrefix, $connection);
    }

    public static function beginNested(?string $name = null): void
    {
        /** @var DatabaseInstance $instance nested transactions are a DatabaseInstance-specific capability */
        $instance = self::getInstance();
        $instance->beginNested($name);
    }

    public static function commitNested(?string $name = null): void
    {
        /** @var DatabaseInstance $instance */
        $instance = self::getInstance();
        $instance->commitNested($name);
    }

    public static function rollBackNested(?string $name = null): void
    {
        /** @var DatabaseInstance $instance */
        $instance = self::getInstance();
        $instance->rollBackNested($name);
    }

    public static function txDepth(?string $name = null): int
    {
        /** @var DatabaseInstance $instance */
        $instance = self::getInstance();
        return $instance->txDepth($name);
    }
}
