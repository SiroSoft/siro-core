<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Database;
use Siro\Core\Lite\BackupManager;

/**
 * Restore SQLite database from a backup file (SQLite only).
 *
 * Usage: php siro db:restore <backup_file>
 */
final class DbRestoreCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $backupFile = isset($args[0]) ? (string) $args[0] : '';
        $force = in_array('--force', $args, true);

        if ($backupFile === '') {
            $this->write('  Usage: php siro db:restore <backup_file> [--force]');
            $this->write('');
            $this->write('  Restore database from a backup file.');
            $this->write('  The backup file is validated before restoring.');
            $this->write('  Existing database will be REPLACED.');
            $this->write('');
            $this->write('  Options:');
            $this->write('    --force    Skip confirmation prompt');
            return 1;
        }

        if (!file_exists($backupFile)) {
            $this->write('  ❌ Backup file not found: ' . $backupFile);
            return 1;
        }

        $config = $this->loadConfig();
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';

        if ($driver !== 'sqlite' && $driver !== 'siro_lite') {
            $this->write('  ❌ db:restore only supports SQLite databases.');
            return 1;
        }

        Database::configure($config);
        try {
            $pdo = Database::connection();
        } catch (\Throwable $e) {
            $this->write('  ❌ Cannot connect to database: ' . $e->getMessage());
            return 1;
        }

        $dbPath = $this->resolveDbPath($config);
        $manager = new BackupManager($pdo, $dbPath);

        $this->write('');
        $this->write('  ⚡ Database Restore');
        $this->write('  ' . str_repeat('=', 40));
        $this->write('  File: ' . basename($backupFile));
        $this->write('  Size: ' . round((int) filesize($backupFile) / 1048576, 2) . ' MB');
        $this->write('');

        if (!$force) {
            $answer = $this->ask('  ⚠ This will REPLACE your database. Continue? (yes/no): ');
            if (strtolower(trim($answer)) !== 'yes') {
                $this->write('  Cancelled.');
                return 1;
            }
        }

        $this->write('  🔍 Validating backup file...');
        $result = $manager->restore($backupFile);

        $this->write('');
        if ($result['success']) {
            $this->write('  ✅ ' . $result['message']);
            $this->write('  Run db:check to verify integrity.');
        } else {
            $this->write('  ❌ ' . $result['message']);
        }
        $this->write('');

        return $result['success'] ? 0 : 1;
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

    /** @param array<string, mixed> $config */
    private function resolveDbPath(array $config): ?string
    {
        $database = is_string($config['database'] ?? null) ? $config['database'] : '';
        if ($database === '' || $database === ':memory:') {
            return null;
        }
        if (DIRECTORY_SEPARATOR === '/' && str_starts_with($database, '/')) {
            return $database;
        }
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Z]:\\\\/i', $database)) {
            return $database;
        }
        return rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($database, './');
    }
}
