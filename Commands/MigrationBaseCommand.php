<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Env;
use Throwable;

/**
 * Shared migration command infrastructure.
 *
 * Abstract base class providing database connection setup,
 * migrations table creation, and PHP extension checks for
 * all migration-related commands.
 *
 * @package Siro\Core\Commands
 */
trait MigrationBaseCommand
{
    use CommandSupport;

    /**
     * Ensure the migrations table exists.
     */
    private function ensureMigrationTable(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'pgsql' => 'CREATE TABLE IF NOT EXISTS migrations (id BIGSERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            default => 'CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        };

        $pdo->exec($sql);

        // Check if batch column already exists before trying to add it
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasBatch = false;
        try {
            $columns = $pdo->query('SELECT batch FROM migrations LIMIT 0');
            $hasBatch = $columns !== false;
        } catch (Throwable) {
            $hasBatch = false;
        }

        if (!$hasBatch) {
            try {
                $alterSql = $driver === 'pgsql'
                    ? 'ALTER TABLE migrations ADD COLUMN IF NOT EXISTS batch INT NOT NULL DEFAULT 1'
                    : 'ALTER TABLE migrations ADD COLUMN batch INT NOT NULL DEFAULT 1';
                $pdo->exec($alterSql);
            } catch (Throwable) {
                // column already exists
            }
        }
    }

    /**
     * Check that required PHP extensions are loaded.
     */
    private function checkRequiredExtensions(): void
    {
        $required = ['pdo', 'json'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        // Check PDO drivers based on DB_CONNECTION
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

    /**
     * Setup database connection from environment.
     *
     * @return PDO
     */
    private function setupDatabaseConnection(string $basePath): PDO
    {
        Env::load($basePath . DIRECTORY_SEPARATOR . '.env');
        
        // Preflight check before attempting DB connection
        $this->checkRequiredExtensions();
        
        /** @var array<string, mixed> $config */
        $config = require $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        Database::configure($config);

        try {
            return Database::connection();
        } catch (\PDOException $e) {
            fwrite(STDERR, "Error: Cannot connect to database\n");
            fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
            fwrite(STDERR, "\nPlease check:\n");
            fwrite(STDERR, "  1. Your .env DB configuration (DB_HOST, DB_PORT, DB_DATABASE)\n");
            fwrite(STDERR, "  2. Database server is running and accessible\n");
            fwrite(STDERR, "  3. Network connectivity and firewall settings\n");
            exit(1);
        }
    }
}
