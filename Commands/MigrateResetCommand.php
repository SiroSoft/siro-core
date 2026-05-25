<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Env;
use Throwable;

final class MigrateResetCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Roll back all migrations.
     *
     * @param array<int, string> $args
     */
    public function run(array $args): int
    {
        unset($args);

        $pdo = $this->setupConnection();
        $this->ensureTable($pdo);

        $migrations = $this->getAllApplied($pdo);
        if ($migrations === []) {
            $this->write('Nothing to reset.');
            return 0;
        }

        $rolledBack = 0;
        foreach ($migrations as $migration) {
            if ($migration === '') {
                continue;
            }

            $path = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $migration;
            if (!is_file($path)) {
                $this->write('Skipped (missing file): ' . $migration);
                $delStmt = $pdo->prepare('DELETE FROM migrations WHERE migration = :migration');
                $delStmt->execute(['migration' => $migration]);
                continue;
            }

            $instance = require $path;
            if (!is_object($instance) || !method_exists($instance, 'down')) {
                $this->write('Skipped (no down()): ' . $migration);
                continue;
            }

            try {
                $pdo->beginTransaction();
                $instance->down($pdo);
                $delStmt = $pdo->prepare('DELETE FROM migrations WHERE migration = :migration');
                $delStmt->execute(['migration' => $migration]);
                $pdo->commit();
                $rolledBack++;
                $this->write('Rolled back: ' . $migration);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->write('Reset failed: ' . $migration);
                $this->write($e->getMessage());
                return 1;
            }
        }

        $this->write('Reset completed. Rolled back ' . $rolledBack . ' migration(s).');
        return 0;
    }

    /** @return list<string> */
    private function getAllApplied(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SELECT migration FROM migrations ORDER BY id DESC');
            if ($stmt === false) {
                return [];
            }
            /** @var list<string> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return $rows ?: [];
        } catch (Throwable) {
            return [];
        }
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
}
