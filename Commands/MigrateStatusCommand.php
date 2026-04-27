<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Env;
use Throwable;

final class MigrateStatusCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        unset($args);

        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');
        
        // Preflight check
        $this->checkRequiredExtensions();
        
        /** @var array<string, mixed> $config */
        $config = require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        Database::configure($config);

        try {
            $pdo = Database::connection();
        } catch (\PDOException $e) {
            fwrite(STDERR, "Error: Cannot connect to database\n");
            fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
            fwrite(STDERR, "\nPlease check:\n");
            fwrite(STDERR, "  1. Your .env DB configuration (DB_HOST, DB_PORT, DB_DATABASE)\n");
            fwrite(STDERR, "  2. Database server is running and accessible\n");
            fwrite(STDERR, "  3. Network connectivity and firewall settings\n");
            exit(1);
        }
        
        $this->ensureMigrationTable($pdo);

        $migrationDir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $files = glob($migrationDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $applied = $this->appliedMigrations($pdo);
        $appliedCount = 0;
        $pendingCount = 0;

        $this->write('Migration status:');
        foreach ($files as $file) {
            $name = basename($file);
            $isApplied = isset($applied[$name]);

            if ($isApplied) {
                $appliedCount++;
                $this->write('[Y] ' . $name . ' (batch ' . $applied[$name] . ')');
            } else {
                $pendingCount++;
                $this->write('[N] ' . $name . ' (pending)');
            }
        }

        $this->write('---');
        $this->write('Applied: ' . $appliedCount);
        $this->write('Pending: ' . $pendingCount);

        return 0;
    }

    private function ensureMigrationTable(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'pgsql' => 'CREATE TABLE IF NOT EXISTS migrations (id BIGSERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            default => 'CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        };

        $pdo->exec($sql);

        try {
            $pdo->exec('ALTER TABLE migrations ADD COLUMN batch INT NOT NULL DEFAULT 1');
        } catch (Throwable) {
            // already exists
        }
    }

    /** @return array<string, int> */
    private function appliedMigrations(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT migration, batch FROM migrations ORDER BY id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $migration = (string) ($row['migration'] ?? '');
            if ($migration === '') {
                continue;
            }

            $result[$migration] = (int) ($row['batch'] ?? 0);
        }

        return $result;
    }

    private function checkRequiredExtensions(): void
    {
        $required = ['pdo', 'json'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        $dbConnection = strtolower((string) Env::get('DB_CONNECTION', 'mysql'));
        $pdoDriver = match ($dbConnection) {
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => 'pdo_mysql',
        };

        if (!extension_loaded($pdoDriver)) {
            $missing[] = $pdoDriver . " (for {$dbConnection})";
        }

        if ($missing !== []) {
            fwrite(STDERR, "Error: Missing required PHP extensions: " . implode(', ', $missing) . PHP_EOL);
            fwrite(STDERR, "Please install them or update your php.ini configuration." . PHP_EOL);
            exit(1);
        }
    }
}
