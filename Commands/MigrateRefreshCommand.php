<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Env;
use Throwable;

final class MigrateRefreshCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Roll back all migrations and re-run them.
     *
     * @param array<int, string> $args
     */
    public function run(array $args): int
    {
        $seed = in_array('--seed', $args, true);

        $resetCommand = new MigrateResetCommand($this->basePath);
        $result = $resetCommand->run([]);
        if ($result !== 0) {
            return $result;
        }

        $this->write('');
        $this->write('Re-running all migrations...');

        $pdo = $this->setupConnection();
        $this->ensureTable($pdo);

        $migrationDir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0775, true);
        }

        $files = glob($migrationDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $ran = 0;
        $batch = $this->nextBatch($pdo);

        foreach ($files as $file) {
            $migrationName = basename($file);

            $instance = require $file;
            if (!is_object($instance) || !method_exists($instance, 'up')) {
                $this->write('Skipped invalid migration: ' . $migrationName);
                continue;
            }

            try {
                $canTransaction = true;
                try {
                    $pdo->beginTransaction();
                } catch (Throwable) {
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

        $this->write('Refresh completed. Ran ' . $ran . ' migration(s).');

        if ($seed) {
            $this->write('');
            $this->write('Running seeders...');
            $seedCommand = new SeedCommand($this->basePath);
            $seedResult = $seedCommand->run([]);
            if ($seedResult !== 0) {
                return $seedResult;
            }
        }

        return 0;
    }

    private function setupConnection(): PDO
    {
        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');
        /** @var array<string, mixed> $config */
        $config = require $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        Database::configure($config);

        try {
            return Database::connection();
        } catch (\PDOException $e) {
            fwrite(STDERR, "Error: Cannot connect to database\n");
            fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    private function ensureTable(PDO $pdo): void
    {
        $driverAttr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : 'mysql';

        $sql = match ($driver) {
            'pgsql' => 'CREATE TABLE IF NOT EXISTS migrations (id BIGSERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            default => 'CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        };

        $pdo->exec($sql);
    }

    private function nextBatch(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query('SELECT MAX(batch) AS max_batch FROM migrations');
            if ($stmt === false) {
                return 1;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $maxRow = is_array($row) ? $row['max_batch'] ?? 0 : 0;
            $max = is_numeric($maxRow) ? (int) $maxRow : 0;
            return $max + 1;
        } catch (Throwable) {
            return 1;
        }
    }
}
