<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use PDO;
use Siro\Core\Database;
use Siro\Core\Env;
use Throwable;

final class MigrateFreshCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Drop all tables and re-run all migrations.
     *
     * Drops every user table, clears the migrations log, then runs
     * all migration files from scratch. With --seed, also runs the
     * database seeder afterwards.
     *
     * @package Siro\Core\Commands
     * @param array<int, string> $args
     */
    public function run(array $args): int
    {
        $seed = in_array('--seed', $args, true);

        Env::load($this->basePath . DIRECTORY_SEPARATOR . '.env');

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
                }

                $instance->up($pdo);

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
