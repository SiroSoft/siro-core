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
        if (in_array('--force-record', $args, true)) {
            $this
                ->warn('The --force-record flag was removed in v1.4.0: failed migrations are never recorded as ' .
                'applied. Fix the migration and re-run.');
        }

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
                    $this
                        ->warn('Transactions are not supported by this driver; running migration without a ' .
                        'transaction: ' .
                        $migrationName);
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

                $msg = $e->getMessage();
                $this->error('Migration failed: ' . $migrationName);
                $this->write($msg);
                return 1;
            }
        }

        $this->write('Migration completed. Ran ' . $ran . ' migration(s).');
        return 0;
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
