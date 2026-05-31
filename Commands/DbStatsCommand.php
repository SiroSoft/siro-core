<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;

/**
 * Show SQLite database statistics — table sizes, index sizes, row estimates.
 *
 * Usage: php siro db:stats
 */
final class DbStatsCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $config = $this->loadConfig();
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';

        if ($driver !== 'sqlite' && $driver !== 'siro_lite') {
            $this->write('  ❌ db:stats only supports SQLite databases.');
            return 1;
        }

        Database::configure($config);
        try {
            $pdo = Database::connection();
        } catch (\Throwable $e) {
            $this->write('  ❌ Cannot connect to database: ' . $e->getMessage());
            return 1;
        }

        $this->write('');
        $this->write('  ⚡ Database Statistics');
        $this->write('  ' . str_repeat('=', 50));
        $this->write('');

        // Database size
        $pageCountVal = $this->safeQuery($pdo, 'PRAGMA page_count') ?? 0;
        $pageCount = is_numeric($pageCountVal) ? (int) $pageCountVal : 0;
        $pageSizeVal = $this->safeQuery($pdo, 'PRAGMA page_size') ?? 0;
        $pageSize = is_numeric($pageSizeVal) ? (int) $pageSizeVal : 0;
        $freePagesVal = $this->safeQuery($pdo, 'PRAGMA freelist_count') ?? 0;
        $freePages = is_numeric($freePagesVal) ? (int) $freePagesVal : 0;
        $totalSize = round(($pageCount * $pageSize) / 1048576, 1);
        $freeSize = round(($freePages * $pageSize) / 1048576, 1);
        $usedSize = round((($pageCount - $freePages) * $pageSize) / 1048576, 1);

        $this->write('  Database Size');
        $this->write('    Total: ' . $totalSize . ' MB');
        $this->write('    Used:  ' . $usedSize . ' MB');
        $this->write('    Free:  ' . $freeSize . ' MB');
        $this->write('');

        // Tables
        $tables = $this->fetchAll($pdo, "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $tableCount = count($tables);

        $this->write('  Tables (' . $tableCount . ')');
        $this->write('');

        // Try sqlite_stat1 for fast row estimates (available after ANALYZE)
        $stat1Exists = false;
        try {
            $chk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sqlite_stat1'");
            $stat1Exists = $chk !== false && $chk->fetchColumn() !== false;
        } catch (\Throwable) {
        }

        /** @var array<string, int> $rowEstimates */
        $rowEstimates = [];
        if ($stat1Exists) {
            $statStmt = $pdo->query("SELECT tbl, stat FROM sqlite_stat1");
            if ($statStmt !== false) {
                $stats = $statStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($stats as $s) {
                    if (!is_array($s)) continue;
                    $tbl = is_string($s['tbl'] ?? null) ? $s['tbl'] : '';
                    $stat = is_string($s['stat'] ?? null) ? $s['stat'] : '';
                    if ($tbl !== '' && $stat !== '') {
                        $parts = explode(' ', $stat);
                        $first = $parts[0];
                        if (is_numeric($first)) {
                            $rowEstimates[$tbl] = (int) $first;
                        }
                    }
                }
            }
        }

        $rows = [];
        foreach ($tables as $table) {
            $name = is_string($table['name'] ?? null) ? $table['name'] : '';
            if ($name === '') {
                continue;
            }
            if (isset($rowEstimates[$name])) {
                $count = $rowEstimates[$name];
            } else {
                $countVal = $this->safeQuery($pdo, "SELECT COUNT(*) FROM \"" . str_replace('"', '""', $name) . '"') ?? 0;
                $count = is_numeric($countVal) ? (int) $countVal : 0;
            }
            $indexCountVal = $this->safeQuery($pdo, "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND tbl_name='" . str_replace("'", "''", $name) . "' AND name NOT LIKE 'sqlite_%'") ?? 0;
            $indexCount = is_numeric($indexCountVal) ? (int) $indexCountVal : 0;
            $label = isset($rowEstimates[$name]) ? 'est' : 'exact';
            $rows[] = [$name, number_format($count), (string) $indexCount, $label];
        }

        if ($rows !== []) {
            $this->table(['Table', 'Rows', 'Indexes', 'Type'], $rows);
        }

        $this->write('');

        // Indexes
        $indexes = $this->fetchAll($pdo, "SELECT name, tbl_name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%' ORDER BY tbl_name, name");
        if ($indexes !== []) {
            $this->write('  Indexes (' . count($indexes) . ')');
            $this->write('');
            $idxRows = [];
            foreach ($indexes as $idx) {
                $name = is_string($idx['name'] ?? null) ? $idx['name'] : '';
                $table = is_string($idx['tbl_name'] ?? null) ? $idx['tbl_name'] : '';
                if ($name !== '' && $table !== '') {
                    $idxRows[] = [$table, $name];
                }
            }
            if ($idxRows !== []) {
                $this->table(['Table', 'Index'], $idxRows);
            }
        }

        $this->write('');
        return 0;
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (!file_exists($configPath)) {
            return ['driver' => 'sqlite'];
        }
        $config = require $configPath;
        if (!is_array($config)) {
            return ['driver' => 'sqlite'];
        }
        /** @var array<string, mixed> $config */
        return $config;
    }

    private function safeQuery(PDO $pdo, string $sql): mixed
    {
        try {
            $stmt = $pdo->query($sql);
            if ($stmt === false) {
                return null;
            }
            return $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAll(PDO $pdo, string $sql): array
    {
        try {
            $stmt = $pdo->query($sql);
            if ($stmt === false) {
                return [];
            }
            /** @var array<int, array<string, mixed>> $result */
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
