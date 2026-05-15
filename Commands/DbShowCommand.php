<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Database;
use Siro\Core\Env;

final class DbShowCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $table = trim((string) ($args[0] ?? ''));
        if ($table === '') {
            $this->write('Usage: php siro db:show <table> [--limit=N]');
            $this->write('       php siro db:show <table> --schema');
            return 1;
        }

        $limit = 20;
        $showSchema = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--limit=')) {
                $limit = max(1, (int) substr($arg, 7));
            }
            if ($arg === '--schema') {
                $showSchema = true;
            }
        }

        try {
            $this->initDatabase();
            if ($showSchema) {
                return $this->showSchema($table);
            }
            return $this->showData($table, $limit);
        } catch (\Throwable $e) {
            $this->write("Error: " . $e->getMessage());
            return 1;
        }
    }

    private function initDatabase(): void
    {
        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');
        /** @var array<string, mixed> $dbConfig */
        $dbConfig = require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        Database::configure($dbConfig);
    }

    private function showSchema(string $table): int
    {
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $columns = match ($driver) {
            'sqlite' => Database::select("PRAGMA table_info({$this->quote($table)})"),
            'pgsql' => Database::select("
                SELECT column_name AS name, data_type AS type,
                       is_nullable AS nullable, column_default AS default
                FROM information_schema.columns
                WHERE table_name = :table
            ", ['table' => $table]),
            default => Database::select("
                SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type,
                       IS_NULLABLE AS nullable, COLUMN_DEFAULT AS `default`
                FROM information_schema.COLUMNS
                WHERE TABLE_NAME = :table AND TABLE_SCHEMA = DATABASE()
            ", ['table' => $table]),
        };

        if ($columns === []) {
            $this->write("Table '{$table}' not found or has no columns.");
            return 1;
        }

        $this->write("Schema: {$table}");
        $this->write('');

        $headers = ['Column', 'Type', 'Nullable', 'Default'];
        $rows = [];
        foreach ($columns as $col) {
            /** @var array<string, mixed> $col */
            $rows[] = [
                $this->safeStr($col['name'] ?? $col['COLUMN_NAME'] ?? ''),
                $this->safeStr($col['type'] ?? $col['COLUMN_TYPE'] ?? ''),
                $this->safeStr($col['nullable'] ?? ($col['IS_NULLABLE'] ?? '')),
                $this->safeStr($col['default'] ?? $col['COLUMN_DEFAULT'] ?? ''),
            ];
        }

        $this->table($headers, $rows);
        return 0;
    }

    private function showData(string $table, int $limit): int
    {
        $count = Database::first("SELECT COUNT(*) AS c FROM {$this->quote($table)}");
        $countC = is_array($count) ? $count['c'] ?? 0 : 0;
        $total = is_numeric($countC) ? (int) $countC : 0;

        $this->write("Table: {$table} ({$total} rows)");
        $this->write('');

        if ($total === 0) {
            $this->write('(empty)');
            return 0;
        }

        $rows = Database::select("SELECT * FROM {$this->quote($table)} LIMIT " . max(1, $limit));

        if ($rows === []) {
            $this->write('(empty)');
            return 0;
        }

        $headers = array_keys($rows[0]);
        $data = [];
        foreach ($rows as $row) {
            $data[] = array_map(function (mixed $val): string {
                if ($val === null) return 'NULL';
                if (is_bool($val)) return $val ? 'true' : 'false';
                $s = $this->safeStr($val);
                return strlen($s) > 60 ? mb_substr($s, 0, 60) . '...' : $s;
            }, array_values($row));
        }

        $this->table($headers, $data);

        if ($total > $limit) {
            $this->write("Showing {$limit} of {$total} rows. Use --limit=N to show more.");
        }

        return 0;
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
