<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\DB\QueryBuilder;
use Siro\Core\DB\RawExpression;

/**
 * Facade for Database::table().
 *
 * Provides syntactic sugar: DB::table('users') instead of
 * Database::table('users').
 *
 * @package Siro\Core
 */
final class DB
{
    public static function table(string $table): QueryBuilder
    {
        return Database::table($table);
    }

    /**
     * Create a raw expression for use in queries.
     *
     * Passthrough for RawExpression. Useful in groupBy, orderBy, select:
     *   DB::table('users')->groupBy(DB::raw('YEAR(created_at)'))
     */
    public static function raw(string $value): RawExpression
    {
        return new RawExpression($value);
    }
}
