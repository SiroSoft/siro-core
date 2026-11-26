<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Env;
use Throwable;

final class MigrateFreshCommand implements \Siro\Core\Commands\CommandInterface
{
    use MigrationBaseCommand;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Drop all tables and re-run all migrations.
     *
     * Drops every user table, then runs all migration files from
     * scratch and rebuilds the migrations log from the beginning
     * (all entries recorded with batch 1). With --seed, also runs
     * the database seeder afterwards.
     *
     * Running in production requires --force, or an interactive
     * confirmation (typing 'yes').
     *
     * @package Siro\Core\Commands
     * @param array<int, string> $args
     */
    public function run(array $args): int
    {
        $seed = in_array('--seed', $args, true);

        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');

        $this->checkRequiredExtensions();

        // Destructive command: refuse to run in production without an
        // explicit --force flag or an interactive confirmation.
        $isProduction = strtolower((string) Env::get('APP_ENV', 'local')) === 'production';
        $force = in_array('--force', $args, true);
        if ($isProduction && !$force) {
            if (stream_isatty(STDIN)) {
                fwrite(STDOUT, "You are about to DROP ALL TABLES in production. Type 'yes' to continue: ");
                $answer = fgets(STDIN);
                if ($answer === false || strtolower(trim($answer)) !== 'yes') {
                    $this->write('Aborted. Use --force to run migrate:fresh in production.');
                    return 1;
                }
            } else {
                $this->write('Refusing to run migrate:fresh in production without --force (non-interactive session).');
                return 1;
            }
        }
        if ($isProduction && $force) {
            $this->write('WARNING: Dropping all tables in production (--force).');
        }

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
            return 1;
        }

        $driverAttr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : 'mysql';

        $this->write('Dropping all tables...');

        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            if ($stmt !== false) {
                /** @var array<int, string> $tables */
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS "' . $table . '"');
                }
            }
        } elseif (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            $pdo->exec('SET session_replication_role = replica');
            $stmt = $pdo->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
            if ($stmt !== false) {
                /** @var array<int, string> $tables */
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS "' . $table . '" CASCADE');
                }
            }
            $pdo->exec('SET session_replication_role = origin');
        } else {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $stmt = $pdo->query('SHOW TABLES');
            if ($stmt !== false) {
                /** @var array<int, string> $tables */
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->info('All tables dropped.');

        // Rebuild the migrations log from scratch (fresh starts a new
        // history, so every entry is recorded with batch 1).
        $this->ensureMigrationTable($pdo);

        $migrationDir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migrationDir)) {
            mkdir($migrationDir, 0775, true);
        }

        $files = glob($migrationDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $ran = 0;

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
                    $this->warn('Transactions are not supported by this driver; running migration without a transaction: ' . $migrationName);
                }

                $instance->up($pdo);
                $stmt = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)');
                $stmt->execute([
                    'migration' => $migrationName,
                    'batch' => 1,
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

        $this->success('Migration completed. Ran ' . $ran . ' migration(s).');

        if ($seed) {
            $this->write('');
            $this->write('Running seeders...');
            $seedCommand = new SeedCommand($this->basePath);
            $result = $seedCommand->run([]);
            if ($result !== 0) {
                return $result;
            }
        }

        return 0;
    }
}
