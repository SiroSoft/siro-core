<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Database;
use Siro\Core\Lite\LiteConfig;

/**
 * Optimize SQLite database — ANALYZE, VACUUM, PRAGMA optimize.
 *
 * Usage: php siro db:optimize
 */
final class DbOptimizeCommand implements \Siro\Core\Commands\CommandInterface {
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
            $this->write('  ❌ db:optimize only supports SQLite databases.');
            return 1;
        }

        Database::configure($config);
        try {
            $pdo = Database::connection();
        } catch (\Throwable $e) {
            $this->write('  ❌ Cannot connect to database: ' . $e->getMessage());
            return 1;
        }

        $liteConfig = new LiteConfig();
        $liteConfig->loadFromConfig($config);

        $this->write('');
        $this->write('  ⚡ Database Optimization');
        $this->write('  ' . str_repeat('=', 40));

        $ok = true;

        // ANALYZE
        try {
            $pdo->exec('ANALYZE');
            $this->write('  ✅ ANALYZE completed');
        } catch (\Throwable $e) {
            $this->write('  ❌ ANALYZE failed: ' . $e->getMessage());
            $ok = false;
        }

        $before = 0;
        $dbPath = $this->resolveDbPath($config);
        if ($dbPath !== null && file_exists($dbPath)) {
            $before = (int) filesize($dbPath);
        }

        // VACUUM
        try {
            $pdo->exec('VACUUM');
            $this->write('  ✅ VACUUM completed');
        } catch (\Throwable $e) {
            $this->write('  ❌ VACUUM failed: ' . $e->getMessage());
            $ok = false;
        }

        if ($dbPath !== null && file_exists($dbPath)) {
            $after = (int) filesize($dbPath);
            if ($before > 0 && $after > 0) {
                $saved = round((($before - $after) / $before) * 100, 1);
                $beforeMb = round($before / 1048576, 1);
                $afterMb = round($after / 1048576, 1);
                $this->write('  📦 Size: ' . $beforeMb . ' MB → ' . $afterMb . ' MB (-' . $saved . '%)');
            }
        }

        // PRAGMA optimize
        try {
            $pdo->exec('PRAGMA optimize');
            $this->write('  ✅ Statistics refreshed (PRAGMA optimize)');
        } catch (\Throwable $e) {
            $this->write('  ⚠ PRAGMA optimize: ' . $e->getMessage());
        }

        $this->write('  ' . str_repeat('-', 40));
        $this->write('  ' . ($ok ? '✅ Optimization complete' : '⚠ Some steps failed'));
        $this->write('');

        return $ok ? 0 : 1;
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
