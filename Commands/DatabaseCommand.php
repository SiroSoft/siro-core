<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\RuntimeManager;

final class DatabaseCommand implements CommandInterface
{
    use CommandSupport;

    public function __construct(string $basePath = '')
    {
        $basePath = '';
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $action = $args[0] ?? 'init';
        $isMysql = in_array('--mysql', $args, true);
        $isMySQLOfficial = in_array('--mysql-official', $args, true);

        return match ($action) {
            'init' => $isMySQLOfficial ? $this->initMySQLOfficial()
                   : ($isMysql ? $this->initMysql() : $this->initSqlite()),
            'start' => $this->startDb(),
            'stop' => $this->stopDb(),
            'status' => $this->status(),
            'remove' => $this->removeDb(),
            default => $this->help(),
        };
    }

    private function initSqlite(): int
    {
        $envPath = getcwd() . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) {
            $this->write('No .env file found. Run this from your project root.');
            return 1;
        }

        $env = file_get_contents($envPath);
        if ($env === false) {
            $this->write('Failed to read .env');
            return 1;
        }

        $env = preg_replace('/^DB_CONNECTION=.*$/m', 'DB_CONNECTION=sqlite', $env) ?? $env;
        $env = preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=', $env) ?? $env;
        $env = preg_replace('/^DB_PORT=.*$/m', 'DB_PORT=', $env) ?? $env;
        $env = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=storage/app/database.sqlite', $env) ?? $env;

        if (str_contains($env, 'DB_CONNECTION=')) {
            file_put_contents($envPath, $env);
        } else {
            file_put_contents($envPath, $env . "\nDB_CONNECTION=sqlite\nDB_DATABASE=storage/app/database.sqlite\n", FILE_APPEND);
        }

        // Ensure SQLite file exists
        $dbFile = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'database.sqlite';
        $dbDir = dirname($dbFile);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        if (!is_file($dbFile)) {
            file_put_contents($dbFile, '');
        }

        $this->success('SQLite configured');
        return 0;
    }

    private function initMySQLOfficial(): int
    {
        $manager = new RuntimeManager();
        $result = $manager->installMySQL();

        if (!$result['success']) {
            $this->error($result['message']);
            return 1;
        }

        $envPath = getcwd() . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) {
            $this->write('No .env file found. Creating one...');
            copy($envPath . '.example', $envPath);
        }

        $env = file_get_contents($envPath);
        if ($env === false) {
            $this->error('Failed to read .env');
            return 1;
        }

        $port = $result['port'] ?? '3306';
        $env = preg_replace('/^DB_CONNECTION=.*$/m', 'DB_CONNECTION=mysql', $env) ?? $env;
        $env = preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=127.0.0.1', $env) ?? $env;
        $env = preg_replace('/^DB_PORT=.*$/m', "DB_PORT={$port}", $env) ?? $env;
        $env = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=siro_dev', $env) ?? $env;

        file_put_contents($envPath, $env);

        $detected = isset($result['existing']) && $result['existing'];
        if ($detected) {
            $this->success("Existing MySQL found on port {$port}");
        } else {
            $this->success($result['message']);
            $this->write("  Database: siro_dev");
            $this->write("  Port: {$port}");
            $this->write("  Username: root");
            $this->write("  Password: (none)");
        }
        return 0;
    }

    private function initMysql(): int
    {
        $manager = new RuntimeManager();
        $result = $manager->installMariaDB();

        if (!$result['success']) {
            $this->error($result['message']);
            return 1;
        }

        $envPath = getcwd() . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) {
            $this->write('No .env file found. Creating one...');
            copy($envPath . '.example', $envPath);
        }

        $env = file_get_contents($envPath);
        if ($env === false) {
            $this->error('Failed to read .env');
            return 1;
        }

        $port = $result['port'] ?? '3306';
        $env = preg_replace('/^DB_CONNECTION=.*$/m', 'DB_CONNECTION=mysql', $env) ?? $env;
        $env = preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=127.0.0.1', $env) ?? $env;
        $env = preg_replace('/^DB_PORT=.*$/m', "DB_PORT={$port}", $env) ?? $env;
        $env = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=siro_dev', $env) ?? $env;

        file_put_contents($envPath, $env);

        $detected = isset($result['existing']) && $result['existing'];
        if ($detected) {
            $this->success("Existing MySQL/MariaDB found on port {$port}");
            $this->write("  Using system database — no download needed");
        } else {
            $this->success($result['message']);
            $this->write("  Database: siro_dev");
            $this->write("  Port: {$port}");
            $this->write("  Username: root");
            $this->write("  Password: (none)");
        }
        return 0;
    }

    private function startDb(): int
    {
        $manager = new RuntimeManager();
        $result = $manager->startMariaDB();
        if ($result['success']) {
            $this->success($result['message']);
            return 0;
        }
        $this->error($result['message']);
        return 1;
    }

    private function stopDb(): int
    {
        $manager = new RuntimeManager();
        $result = $manager->stopMariaDB();
        if ($result['success']) {
            $this->success($result['message']);
            return 0;
        }
        $this->error($result['message']);
        return 1;
    }

    private function status(): int
    {
        $mgr = new RuntimeManager();
        $status = $mgr->dbStatus();

        if ($status['installed']) {
            $this->write("MariaDB: installed");
            $this->write("  Port: {$status['port']}");
            $this->write("  Running: " . ($status['running'] ? 'yes' : 'no'));
            if ($status['running']) {
                $this->write("  PID: {$status['pid']}");
            }
            $this->write("  Data: {$status['datadir']}");
        } else {
            $this->write("MariaDB not installed.");
            $this->write("  Run: siro db:init --mysql");
        }

        // Show current .env config
        $envPath = getcwd() . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envPath)) {
            $env = file_get_contents($envPath);
            if ($env !== false && preg_match('/^DB_CONNECTION=(\w+)/m', $env, $m)) {
                $this->write("Active driver: {$m[1]}");
            }
        }

        return 0;
    }

    private function removeDb(): int
    {
        $manager = new RuntimeManager();
        $result = $manager->removeMariaDB();
        if ($result['success']) {
            $this->success($result['message']);
            return 0;
        }
        $this->error($result['message']);
        return 1;
    }

    private function help(): int
    {
        $this->write('Database Manager');
        $this->write('');
        $this->write('  db:init                Configure SQLite (default)');
        $this->write('  db:init --mysql        Install & start MariaDB portable');
        $this->write('  db:init --mysql-official Install & start MySQL Community Server');
        $this->write('  db:start               Start database server');
        $this->write('  db:stop                Stop database server');
        $this->write('  db:status              Show database status');
        $this->write('  db:remove              Remove database runtime');
        return 0;
    }
}
