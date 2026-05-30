<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;

/**
 * Benchmark SQLite database performance — inserts, selects, updates, deletes per second.
 *
 * Usage: php siro db:benchmark [--iterations=1000]
 */
final class DbBenchmarkCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $iterations = 1000;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--iterations=')) {
                $val = (int) substr($arg, 13);
                if ($val > 0 && $val <= 50000) {
                    $iterations = $val;
                }
            }
        }

        $config = $this->loadConfig();
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';

        if ($driver !== 'sqlite' && $driver !== 'siro_lite') {
            $this->write('  ❌ db:benchmark only supports SQLite databases.');
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
        $this->write('  ⚡ SQLite Benchmark (' . number_format($iterations) . ' iterations each)');
        $this->write('  ' . str_repeat('=', 50));

        // Create temp benchmark table
        $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS _bench_ (id INTEGER PRIMARY KEY, key TEXT, value TEXT, created_at TEXT)');
        $pdo->exec('DELETE FROM _bench_');

        $this->write('');

        // INSERT benchmark
        $start = microtime(true);
        $stmt = $pdo->prepare('INSERT INTO _bench_ (key, value, created_at) VALUES (?, ?, datetime())');
        $pdo->beginTransaction();
        for ($i = 0; $i < $iterations; $i++) {
            $stmt->execute(['key_' . $i, 'value_' . $i]);
        }
        $pdo->commit();
        $insertTime = (microtime(true) - $start) * 1000;
        $insertPerSec = $insertTime > 0 ? round($iterations / ($insertTime / 1000), 0) : 0;
        $this->write('  INSERT:  ' . number_format($insertPerSec) . ' ops/sec (' . round($insertTime, 1) . 'ms total)');

        // SELECT benchmark (by PK)
        $start = microtime(true);
        $selectStmt = $pdo->prepare('SELECT * FROM _bench_ WHERE id = ?');
        for ($i = 0; $i < $iterations; $i++) {
            $selectStmt->execute([($i % $iterations) + 1]);
            $selectStmt->fetch();
        }
        $selectTime = (microtime(true) - $start) * 1000;
        $selectPerSec = $selectTime > 0 ? round($iterations / ($selectTime / 1000), 0) : 0;
        $this->write('  SELECT:  ' . number_format($selectPerSec) . ' ops/sec (' . round($selectTime, 1) . 'ms total)');

        // SELECT benchmark (non-indexed)
        $start = microtime(true);
        $slowStmt = $pdo->prepare("SELECT * FROM _bench_ WHERE key = ?");
        for ($i = 0; $i < min($iterations, 100); $i++) {
            $slowStmt->execute(['key_' . (string) ($i % $iterations)]);
            $slowStmt->fetch();
        }
        $slowTime = (microtime(true) - $start) * 1000;
        $this->write('  SELECT (non-indexed): ' . number_format($slowTime > 0 ? round(min($iterations, 100) / ($slowTime / 1000), 0) : 0) . ' ops/sec');

        // UPDATE benchmark
        $start = microtime(true);
        $updateStmt = $pdo->prepare('UPDATE _bench_ SET value = ? WHERE id = ?');
        $pdo->beginTransaction();
        for ($i = 0; $i < $iterations; $i++) {
            $updateStmt->execute(['updated_' . $i, ($i % $iterations) + 1]);
        }
        $pdo->commit();
        $updateTime = (microtime(true) - $start) * 1000;
        $updatePerSec = $updateTime > 0 ? round($iterations / ($updateTime / 1000), 0) : 0;
        $this->write('  UPDATE:  ' . number_format($updatePerSec) . ' ops/sec (' . round($updateTime, 1) . 'ms total)');

        // DELETE benchmark
        $start = microtime(true);
        $deleteStmt = $pdo->prepare('DELETE FROM _bench_ WHERE id = ?');
        $pdo->beginTransaction();
        for ($i = 0; $i < $iterations; $i++) {
            $deleteStmt->execute([($i % $iterations) + 1]);
        }
        $pdo->commit();
        $deleteTime = (microtime(true) - $start) * 1000;
        $deletePerSec = $deleteTime > 0 ? round($iterations / ($deleteTime / 1000), 0) : 0;
        $this->write('  DELETE:  ' . number_format($deletePerSec) . ' ops/sec (' . round($deleteTime, 1) . 'ms total)');

        // Real-world benchmark: SELECT against actual app tables
        $realTables = $this->fetchAll($pdo, "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE '_bench_%' ORDER BY name");
        if ($realTables !== []) {
            $largestTable = '';
            $largestCount = 0;
            foreach ($realTables as $rt) {
                $tblName = is_string($rt['name'] ?? null) ? $rt['name'] : '';
                if ($tblName === '') continue;
                $countVal = $this->safeQuery($pdo, "SELECT COUNT(*) FROM \"" . str_replace('"', '""', $tblName) . '"') ?? 0;
                $cnt = is_numeric($countVal) ? (int) $countVal : 0;
                if ($cnt > $largestCount) {
                    $largestCount = $cnt;
                    $largestTable = $tblName;
                }
            }
            if ($largestTable !== '' && $largestCount > 0) {
                $this->write('');
                $this->write('  Real-table benchmark (table: ' . $largestTable . ', ' . number_format($largestCount) . ' rows)');
                $this->write('');

                // SELECT benchmark on real table
                $start = microtime(true);
                $realSelect = $pdo->prepare("SELECT * FROM \"" . str_replace('"', '""', $largestTable) . '" LIMIT ?');
                $limitIter = min($iterations, 100);
                for ($i = 0; $i < $limitIter; $i++) {
                    $realSelect->execute([100]);
                    $realSelect->fetchAll();
                }
                $realTime = (microtime(true) - $start) * 1000;
                $realPerSec = $realTime > 0 ? round($limitIter / ($realTime / 1000), 0) : 0;
                $this->write('  SELECT (real table): ' . number_format($realPerSec) . ' ops/sec (' . round($realTime, 1) . 'ms total)');

                // COUNT benchmark
                $start = microtime(true);
                for ($i = 0; $i < $limitIter; $i++) {
                    $qStmt = $pdo->query("SELECT COUNT(*) FROM \"" . str_replace('"', '""', $largestTable) . '"');
                    if ($qStmt !== false) $qStmt->fetchColumn();
                }
                $countTime = (microtime(true) - $start) * 1000;
                $countPerSec = $countTime > 0 ? round($limitIter / ($countTime / 1000), 0) : 0;
                $this->write('  COUNT:   ' . number_format($countPerSec) . ' ops/sec (' . round($countTime, 1) . 'ms total)');
            }
        }

        // Release statements before DROP TABLE to avoid SQLite locks
        $stmt = null;
        $selectStmt = null;
        $slowStmt = null;
        $updateStmt = null;
        $deleteStmt = null;
        $realSelect = null;

        try { $pdo->exec('DROP TABLE IF EXISTS _bench_'); } catch (\Throwable) { /* ignore cleanup errors */ }

        $this->write('');
        $this->write('  ' . str_repeat('-', 50));

        $versionStmt = $pdo->query('SELECT sqlite_version()');
        $version = $versionStmt !== false ? $versionStmt->fetchColumn() : '?';
        $walStmt = $pdo->query('PRAGMA journal_mode');
        $walMode = $walStmt !== false ? $walStmt->fetchColumn() : '?';
        $this->write('  SQLite v' . $version . ' | Journal: ' . $walMode);
        $this->write('');

        return 0;
    }

    private function safeQuery(PDO $pdo, string $sql): mixed
    {
        try {
            $stmt = $pdo->query($sql);
            if ($stmt === false) return null;
            return $stmt->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchAll(PDO $pdo, string $sql): array
    {
        try {
            $stmt = $pdo->query($sql);
            if ($stmt === false) return [];
            /** @var array<int, array<string, mixed>> $result */
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $result;
        } catch (\Throwable) {
            return [];
        }
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
}
