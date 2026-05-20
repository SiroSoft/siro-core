<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use Siro\Core\DB\QueryBuilder;
use Siro\Core\DB\RawExpression;

/**
 * Facade for Database operations.
 *
 * Provides syntactic sugar for common Database methods.
 *
 * @package Siro\Core
 */
final class DB
{
    public static function connection(?string $name = null): PDO
    {
        return Database::connection($name);
    }

    public static function table(string $table): QueryBuilder
    {
        return Database::table($table);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function select(string $sql, array $params = [], ?string $connection = null): array
    {
        return Database::select($sql, $params, $connection);
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public static function execute(string $sql, array $params = [], ?string $connection = null): int
    {
        return Database::execute($sql, $params, $connection);
    }

    /**
     * Create a raw expression for use in queries.
     */
    public static function raw(string $value): RawExpression
    {
        return new RawExpression($value);
    }
}
