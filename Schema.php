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
        $driver = self::driver();
        foreach ($blueprint->compileAlter() as $sql) {
            try {
                self::execute($sql);
            } catch (\Throwable $e) {
                // SQLite: skip "duplicate column" and unsupported FK errors gracefully
                if ($driver === 'sqlite') {
                    $msg = strtolower($e->getMessage());
                    if (str_contains($msg, 'duplicate column name') || str_contains($msg, 'foreign')) {
                        continue;
                    }
                }
                throw $e;
            }
        }
    }

    public static function drop(string $table): void
    {
        self::execute("DROP TABLE IF EXISTS " . self::quoteIdentifier($table));
    }

    public static function dropIfExists(string $table): void
    {
        self::execute("DROP TABLE IF EXISTS " . self::quoteIdentifier($table));
    }

    public static function dropColumn(string $table, string $column): void
    {
        $driver = self::driver();
        $qt = self::quoteIdentifier($table);
        $qc = self::quoteIdentifier($column);
        if ($driver === 'pgsql') {
            self::execute("ALTER TABLE {$qt} DROP COLUMN IF EXISTS {$qc}");
        } else {
            try {
                self::execute("ALTER TABLE {$qt} DROP COLUMN {$qc}");
            } catch (\Throwable) {
            }
        }
    }

    public static function renameColumn(string $table, string $from, string $to): void
    {
        $driver = self::driver();
        $qt = self::quoteIdentifier($table);
        $qf = self::quoteIdentifier($from);
        $qto = self::quoteIdentifier($to);
        $sql = match ($driver) {
            'pgsql' => "ALTER TABLE {$qt} RENAME COLUMN {$qf} TO {$qto}",
            'sqlite' => "ALTER TABLE {$qt} RENAME COLUMN {$qf} TO {$qto}",
            default => "ALTER TABLE {$qt} CHANGE {$qf} {$qto}",
        };
        self::execute($sql);
    }

    public static function rename(string $from, string $to): void
    {
        $driver = self::driver();
        $qf = self::quoteIdentifier($from);
        $qt = self::quoteIdentifier($to);
        if ($driver === 'mysql' || $driver === 'mariadb') {
            self::execute("RENAME TABLE {$qf} TO {$qt}");
        } else {
            self::execute("ALTER TABLE {$qf} RENAME TO {$qt}");
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
        $stmt->execute([':table' => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table)]);
        return (bool) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    /** @return array<int, string> */
    public static function getColumnListing(string $table): array
    {
        $driver = self::driver();
        $sql = match ($driver) {
            'pgsql' => "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table",
            'sqlite' => "SELECT name FROM pragma_table_info(:table)",
            default => "SHOW COLUMNS FROM " . self::quoteIdentifier($table),
        };
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([':table' => $table]);
        return array_values(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return in_array($column, self::getColumnListing($table), true);
    }

    public static function hasDatabase(string $database): bool
    {
        $driver = self::driver();
        $sql = match ($driver) {
            'pgsql' => "SELECT EXISTS (SELECT FROM pg_database WHERE datname = :db)",
            'sqlite' => null, // SQLite uses a file, not a database name
            default => "SELECT EXISTS (SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db)",
        };
        if ($sql === null) {
            return true; // SQLite: always available
        }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([':db' => $database]);
        return (bool) $stmt->fetch(PDO::FETCH_COLUMN);
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
        $driver = self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        return is_string($driver) ? $driver : 'mysql';
    }

    private static function quoteIdentifier(string $identifier): string
    {
        $driver = self::driver();
        $char = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) ? '"' : '`';
        $escaped = str_replace($char, $char . $char, $identifier);
        return $char . $escaped . $char;
    }
}
