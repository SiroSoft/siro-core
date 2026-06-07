<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Lite\HealthAnalyzer;
use Siro\Core\Lite\LiteConfig;

/**
 * Show SQLite database health — size, fragmentation, tables, indexes, WAL status (SQLite only).
 *
 * Usage: php siro db:health
 */
final class DbHealthCommand implements \Siro\Core\Commands\CommandInterface {
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
            $this->write('  ❌ db:health only supports SQLite databases.');
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
        $dbPath = $this->resolveDbPath($config);

        $analyzer = new HealthAnalyzer($pdo, $dbPath);
        $health = $analyzer->analyze();

        $this->write('');
        $this->write('  ⚡ SQLite Health');
        $this->write('  ' . str_repeat('=', 40));
        $this->write('  Database: SQLite');
        $this->write('  Mode:     ' . ($liteConfig->isEnabled() ? 'Siro Lite' : 'Standard SQLite'));
        $this->write('  Version:  ' . $this->safeStr($health['version']));
        $this->write('');
        $this->write('  Size:     ' . $this->safeStr($health['file_size_mb']) . ' MB (file) / ' . $this->safeStr($health['database_size_mb']) . ' MB (pages)');
        $this->write('  Tables:   ' . $this->safeStr($health['table_count']));
        $this->write('  Indexes:  ' . $this->safeStr($health['index_count']));
        $this->write('  Pages:    ' . $this->safeStr($health['page_count']) . ' total, ' . $this->safeStr($health['free_pages']) . ' free');
        $this->write('  Fragm.:   ' . $this->safeStr($health['fragmentation_percent']) . '% (' . $this->safeStr($health['fragmentation_level']) . ')');
        $walEnabled = is_scalar($health['wal_enabled'] ?? false) ? (bool) $health['wal_enabled'] : false;
        $this->write('  WAL:      ' . ($walEnabled ? 'Enabled' : 'Disabled'));
        $walSizeBytes = $health['wal_size_bytes'] ?? 0;
        if (is_numeric($walSizeBytes) && (int) $walSizeBytes > 0) {
            $this->write('  WAL Size: ' . round((float) $walSizeBytes / 1048576, 1) . ' MB');
        }
        $integrityOk = is_scalar($health['integrity_ok'] ?? false) ? (bool) $health['integrity_ok'] : false;
        $this->write('  Integrity: ' . ($integrityOk ? '✅ OK' : '❌ Issues detected'));
        $this->write('  ' . str_repeat('-', 40));

        $status = '✅ Healthy';
        $fragPct = $health['fragmentation_percent'] ?? 0;
        if (is_numeric($fragPct) && (float) $fragPct > 15) {
            $status = '⚠ Fragmented — run db:optimize';
        }
        if (!$integrityOk) {
            $status = '❌ Corruption detected — run db:check';
        }
        $this->write('  Status: ' . $status);
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
