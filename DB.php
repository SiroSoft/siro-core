<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\DB\QueryBuilder;

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
}
