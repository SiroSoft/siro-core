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

    public static function resetPdo(): void
    {
        self::$pdo = null;
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
        // NOTE: MySQL does not accept bound parameters in SHOW statements
        // under native prepares (PDO::ATTR_EMULATE_PREPARES => false yields
        // SQLSTATE 42000 near '?'), so the MySQL branch queries
        // information_schema with an exact = comparison instead of
        // SHOW TABLES LIKE. Exact match also removes LIKE wildcard concerns
        // for names containing % or _ (e.g. temp_tbl).
        $sql = match ($driver) {
            'pgsql' => "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table)",
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name=:table",
            default => "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table",
        };
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    /** @return array<int, string> */
    public static function getColumnListing(string $table): array
    {
        $driver = self::driver();
        // NOTE: same native-prepare constraint as hasTable(): the MySQL
        // branch must not mix a placeholder-free SHOW statement with bound
        // parameters (PDO MySQL raises HY093 invalid parameter number), so
        // it reads information_schema instead.
        $sql = match ($driver) {
            'pgsql' => "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table",
            'sqlite' => "SELECT name FROM pragma_table_info(:table)",
            default => "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table ORDER BY ordinal_position",
        };
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([':table' => $table]);
        return array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return in_array($column, self::getColumnListing($table), true);
    }

    public static function hasForeignKey(string $table, string $column, ?string $referencedTable = null): bool
    {
        $driver = self::driver();
        $referencedTable ??= '';

        if ($driver === 'sqlite') {
            $stmt = self::pdo()->query('PRAGMA foreign_key_list(' . self::quoteIdentifier($table) . ')');
            $foreignKeys = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($foreignKeys as $foreignKey) {
                if (!is_array($foreignKey)) {
                    continue;
                }
                if (($foreignKey['from'] ?? '') !== $column) {
                    continue;
                }
                if ($referencedTable === '' || ($foreignKey['table'] ?? '') === $referencedTable) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'pgsql') {
            $sql = 'SELECT COUNT(*) FROM information_schema.key_column_usage k '
                . 'JOIN information_schema.constraint_column_usage r '
                . 'ON r.constraint_name = k.constraint_name '
                . 'AND r.constraint_schema = k.constraint_schema '
                . 'WHERE k.table_schema = current_schema() '
                . 'AND k.table_name = :table AND k.column_name = :column';
            if ($referencedTable !== '') {
                $sql .= ' AND r.table_name = :referenced_table';
            }
        } else {
            $sql = 'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table '
                . 'AND COLUMN_NAME = :column AND REFERENCED_TABLE_NAME IS NOT NULL';
            if ($referencedTable !== '') {
                $sql .= ' AND REFERENCED_TABLE_NAME = :referenced_table';
            }
        }

        $params = ['table' => $table, 'column' => $column];
        if ($referencedTable !== '') {
            $params['referenced_table'] = $referencedTable;
        }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
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
