<?php

declare(strict_types=1);

namespace Siro\Core;

use PDO;
use RuntimeException;
use Siro\Core\DB\Blueprint;

final class Schema
{
    private static ?PDO $pdo = null;

    public static function connect(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, self::driver());
        $callback($blueprint);
        foreach ($blueprint->compileCreate() as $sql) {
            self::execute($sql);
        }
    }

    public static function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, self::driver());
        $callback($blueprint);
        self::execute($blueprint->compileAlter());
    }

    public static function drop(string $table): void
    {
        self::execute("DROP TABLE IF EXISTS {$table}");
    }

    public static function dropIfExists(string $table): void
    {
        self::execute("DROP TABLE IF EXISTS {$table}");
    }

    public static function dropColumn(string $table, string $column): void
    {
        $driver = self::driver();
        if ($driver === 'pgsql') {
            self::execute("ALTER TABLE {$table} DROP COLUMN IF EXISTS {$column}");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support DROP COLUMN natively before 3.35
            // Try it anyway
            try {
                self::execute("ALTER TABLE {$table} DROP COLUMN {$column}");
            } catch (\Throwable) {
                // Ignore if column doesn't exist
            }
        } else {
            try {
                self::execute("ALTER TABLE {$table} DROP COLUMN {$column}");
            } catch (\Throwable) {
                // Ignore if column doesn't exist
            }
        }
    }

    public static function rename(string $from, string $to): void
    {
        $driver = self::driver();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            self::execute("RENAME TABLE {$from} TO {$to}");
        } else {
            self::execute("ALTER TABLE {$from} RENAME TO {$to}");
        }
    }

    public static function hasTable(string $table): bool
    {
        $driver = self::driver();
        $sql = match ($driver) {
            'pgsql' => "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table)",
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name=:table",
            default => "SHOW TABLES LIKE :table",
        };
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    /** @return array<int, string> */
    public static function getColumnListing(string $table): array
    {
        $driver = self::driver();
        $sql = match ($driver) {
            'pgsql' => "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table",
            'sqlite' => "SELECT name FROM pragma_table_info(:table)",
            default => "SHOW COLUMNS FROM {$table}",
        };
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([':table' => $table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return in_array($column, self::getColumnListing($table), true);
    }

    private static function execute(string $sql): void
    {
        self::pdo()->exec($sql);
    }

    private static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = Database::connection();
        }
        return self::$pdo;
    }

    private static function driver(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
