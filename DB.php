<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\DB\QueryBuilder;

final class DB
{
    public static function table(string $table): QueryBuilder
    {
        return Database::table($table);
    }
}
