<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Env;
use Throwable;

final class MigrateCommand implements \Siro\Core\Commands\CommandInterface
{
    use MigrationBaseCommand;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
 * Run pending database migrations.
 *
 * Executes all migration files that have not yet been applied,
 * in batch order, with optional transaction support.
 *
 * @package Siro\Core\Commands
 * @param array<int, string> $args
 */
    public function run(array $args): int
    {
        unset($args);

        $pdo = $this->setupDatabaseConnection($this->basePath);
        
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

        $this->info('Pending migrations: ' . $pending);
        $this->info('Running batch: ' . $batch);

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
                $this->success('Migrated: ' . $migrationName);
            } catch (Throwable $e) {
                if ($canTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $this->error('Migration failed: ' . $migrationName);
                $this->write($e->getMessage());
                return 1;
            }
        }

        $this->write('Migration completed. Ran ' . $ran . ' migration(s).');
        return 0;
    }

    private function ensureMigrationTable(PDO $pdo): void
    {
        $driverAttr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : 'mysql';

        $sql = match ($driver) {
            'pgsql' => 'CREATE TABLE IF NOT EXISTS migrations (id BIGSERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            default => 'CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        };

        $pdo->exec($sql);

        // Check if batch column already exists before trying to add it
        $driverAttr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : 'mysql';
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

    /** @return array<string, true> */
    private function executedMigrations(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT migration FROM migrations');
        if ($stmt === false) {
            return [];
        }
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
        if ($stmt === false) {
            return 1;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $maxRow = is_array($row) ? $row['max_batch'] ?? 0 : 0;
        $max = is_numeric($maxRow) ? (int) $maxRow : 0;

        return $max + 1;
    }
}
