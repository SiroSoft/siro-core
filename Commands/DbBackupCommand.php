<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Database;
use Siro\Core\Lite\BackupManager;

/**
 * Backup SQLite database to storage/backups/ (SQLite only).
 *
 * Usage: php siro db:backup [--compress]
 */
final class DbBackupCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $compress = in_array('--compress', $args, true);

        $config = $this->loadConfig();
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';

        if ($driver !== 'sqlite' && $driver !== 'siro_lite') {
            $this->write('  ❌ db:backup only supports SQLite databases.');
            return 1;
        }

        Database::configure($config);
        try {
            $pdo = Database::connection();
        } catch (\Throwable $e) {
            $this->write('  ❌ Cannot connect to database: ' . $e->getMessage());
            return 1;
        }

        $backupDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
        $dbPath = $this->resolveDbPath($config);

        $manager = new BackupManager($pdo, $dbPath);

        $this->write('');
        $this->write('  ⚡ Database Backup');
        $this->write('  ' . str_repeat('=', 40));

        try {
            $result = $manager->backup($backupDir, $compress);

            $sizeMb = round($result['size'] / 1048576, 2);
            $this->write('  ✅ Backup created');
            $this->write('  📁 ' . $result['path']);
            $this->write('  📦 ' . $sizeMb . ' MB' . ($result['compressed'] ? ' (gzip compressed)' : ''));
            $this->write('');

            // List recent backups
            $this->write('  Recent backups:');
            $files = glob(rtrim($backupDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.db*');
            if (is_array($files)) {
                rsort($files);
                foreach (array_slice($files, 0, 5) as $f) {
                    $fsize = filesize($f);
                    $fsizeMb = round($fsize / 1048576, 2);
                    $this->write('    ' . basename($f) . ' (' . $fsizeMb . ' MB)');
                }
            }
            $this->write('');

            return 0;
        } catch (\Throwable $e) {
            $this->write('  ❌ Backup failed: ' . $e->getMessage());
            return 1;
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
