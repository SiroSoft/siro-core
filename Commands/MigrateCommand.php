<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Env;
use Throwable;

final class MigrateCommand
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
        
        // Preflight check before attempting DB connection
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
        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0775, true);
        }

        $files = glob($migrationDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $executed = $this->executedMigrations($pdo);
        $pending = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (!isset($executed[$name])) {
                $pending++;
            }
        }

        if ($pending === 0) {
            $this->write('Nothing to migrate.');
            $this->write('Use "php siro migrate:status" to view migration state.');
            return 0;
        }

        $ran = 0;
        $batch = $this->nextBatch($pdo);

        $this->write('Pending migrations: ' . $pending);
        $this->write('Running batch: ' . $batch);

        foreach ($files as $file) {
            $migrationName = basename($file);
            if (isset($executed[$migrationName])) {
                continue;
            }

            $instance = require $file;
            if (!is_object($instance) || !method_exists($instance, 'up')) {
                $this->write('Skipped invalid migration: ' . $migrationName);
                continue;
            }

            try {
                // Check if transaction is supported
                $canTransaction = true;
                try {
                    $pdo->beginTransaction();
                } catch (Throwable $te) {
                    // Transaction not supported, continue without it
                    $canTransaction = false;
                }

                $instance->up($pdo);
                $stmt = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)');
                $stmt->execute([
                    'migration' => $migrationName,
                    'batch' => $batch,
                ]);
                
                if ($canTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
                
                $ran++;
                $this->write('Migrated: ' . $migrationName);
            } catch (Throwable $e) {
                if ($canTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $this->write('Migration failed: ' . $migrationName);
                $this->write($e->getMessage());
                return 1;
            }
        }

        $this->write('Migration completed. Ran ' . $ran . ' migration(s).');
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
            // batch column already exists
        }
    }

    /** @return array<string, true> */
    private function executedMigrations(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT migration FROM migrations');
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $executed = [];
        foreach ($rows as $migration) {
            if (is_string($migration) && $migration !== '') {
                $executed[$migration] = true;
            }
        }

        return $executed;
    }

    private function nextBatch(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT MAX(batch) AS max_batch FROM migrations');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $max = (int) ($row['max_batch'] ?? 0);

        return $max + 1;
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
}
