<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Database;

/**
 * Run SQLite integrity check — PRAGMA integrity_check + foreign_key_check.
 *
 * Usage: php siro db:check
 */
final class DbCheckCommand implements \Siro\Core\Commands\CommandInterface {
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
            $this->write('  ❌ db:check only supports SQLite databases.');
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
        $this->write('  ⚡ Database Integrity Check');
        $this->write('  ' . str_repeat('=', 40));

        $allOk = true;

        // 1. integrity_check
        try {
            $stmt = $pdo->query('PRAGMA integrity_check');
            if ($stmt === false) {
                $this->write('  ❌ integrity_check query failed');
                $allOk = false;
            } else {
                $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                if (in_array('ok', $rows, true) && count($rows) === 1) {
                    $this->write('  ✅ Database integrity OK');
                } else {
                    $this->write('  ❌ Corruption detected:');
                    foreach ($rows as $row) {
                        $rowStr = is_string($row) ? $row : '';
                        if ($rowStr !== 'ok') {
                            $this->write('     - ' . $rowStr);
                        }
                    }
                    $allOk = false;
                }
            }
        } catch (\Throwable $e) {
            $this->write('  ❌ integrity_check failed: ' . $e->getMessage());
            $allOk = false;
        }

        // 2. foreign_key_check
        try {
            $stmt = $pdo->query('PRAGMA foreign_key_check');
            if ($stmt !== false) {
                $violations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if ($violations === []) {
                    $this->write('  ✅ Foreign keys: all valid');
                } else {
                    $this->write('  ⚠ Foreign key violations:');
                    foreach ($violations as $v) {
                        if (is_array($v)) {
                            $rowId = $this->safeStr($v['rowid'] ?? '');
                            $parent = $this->safeStr($v['parent'] ?? '');
                            $this->write("     - rowid={$rowId} references {$parent}");
                        }
                    }
                    $allOk = false;
                }
            }
        } catch (\Throwable $e) {
            $this->write('  ⚠ foreign_key_check: ' . $e->getMessage());
        }

        // 3. Quick index check
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'");
            if ($stmt !== false) {
                $indexCount = (int) $stmt->fetchColumn();
                $this->write('  📊 Indexes: ' . $indexCount . ' defined');
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $this->write('  ' . str_repeat('-', 40));
        $status = $allOk ? '✅ Database integrity OK. No action required.' : '❌ Issues detected. Consider restore from backup.';
        $this->write('  ' . $status);
        $this->write('');

        return $allOk ? 0 : 1;
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
