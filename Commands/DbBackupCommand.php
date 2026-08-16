<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Database;
use Siro\Core\Lite\BackupManager;

/**
 * Backup SQLite database to storage/backups/ (SQLite only).
 *
 * Usage: php siro db:backup [--compress]
 */
final class DbBackupCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $compress = in_array('--compress', $args, true);

        $config = $this->loadConfig();
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';

        if ($driver !== 'sqlite' && $driver !== 'siro_lite' && $driver !== 'mysql' && $driver !== 'mariadb') {
            $this->write('  ❌ db:backup supports SQLite and MySQL databases.');
            return 1;
        }

        $backupDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0775, true);
        }

        $this->write('');
        $this->write('  ⚡ Database Backup');
        $this->write('  ' . str_repeat('=', 40));

        try {
            $result = $driver === 'mysql' || $driver === 'mariadb'
                ? $this->mysqlBackup($config, $backupDir, $compress)
                : $this->sqliteBackup($config, $backupDir, $compress);

            if (!$result['success']) {
                $this->write('  ❌ ' . $result['message']);
                return 1;
            }

            $sizeMb = round($result['size'] / 1048576, 2);
            $this->write('  ✅ Backup created');
            $this->write('  📁 ' . $result['path']);
            $this->write('  📦 ' . $sizeMb . ' MB' . ($result['compressed'] ? ' (gzip compressed)' : ''));
            $this->write('');

            // List recent backups
            $this->write('  Recent backups:');
            $files = glob(rtrim($backupDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backup-*');
            if (is_array($files)) {
                rsort($files);
                foreach (array_slice($files, 0, 5) as $f) {
                    $fsize = file_exists($f) ? (int) filesize($f) : 0;
                    $fsizeMb = round($fsize / 1048576, 2);
                    $this->write('    ' . basename($f) . ' (' . $fsizeMb . ' MB)');
                }
            }
            $this->write('');

            return 0;
        } catch (\Throwable $e) {
            $this->write('  ❌ Backup failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, message: string, path: string, size: int, compressed: bool}
     */
    private function sqliteBackup(array $config, string $backupDir, bool $compress): array
    {
        Database::configure($config);
        $pdo = Database::connection();
        $dbPath = $this->resolveDbPath($config);
        $manager = new BackupManager($pdo, $dbPath);
        return $manager->backup($backupDir, $compress);
    }

    /**
     * MySQL backup via mysqldump (preferred) or a PHP logical dump fallback.
     *
     * @param array<string, mixed> $config
     * @return array{success: bool, message: string, path: string, size: int, compressed: bool}
     */
    private function mysqlBackup(array $config, string $backupDir, bool $compress): array
    {
        $host = is_string($config['host'] ?? null) ? $config['host'] : '127.0.0.1';
        $port = is_numeric($config['port'] ?? null) ? (int) $config['port'] : 3306;
        $user = is_string($config['username'] ?? null) ? $config['username'] : '';
        $pass = is_string($config['password'] ?? null) ? $config['password'] : '';
        $database = is_string($config['database'] ?? null) ? $config['database'] : '';

        if ($database === '') {
            return ['success' => false, 'message' => 'MySQL database name not configured', 'path' => '', 'size' => 0, 'compressed' => false];
        }

        $stamp = date('Y-m-d-His');
        $path = $backupDir . DIRECTORY_SEPARATOR . 'backup-' . $stamp . '.sql';

        // Prefer mysqldump when available
        $mysqldump = $this->findMysqldump();
        if ($mysqldump !== null) {
            $cmd = sprintf(
                '"%s" --host=%s --port=%d --user=%s %s %s --routines --single-transaction > "%s" 2>&1',
                $mysqldump,
                escapeshellarg($host),
                $port,
                escapeshellarg($user),
                $pass !== '' ? '--password=' . escapeshellarg($pass) : '',
                escapeshellarg($database),
                $path,
            );
            exec($cmd, $outLines, $exitCode);
            if ($exitCode === 0 && file_exists($path) && (int) filesize($path) > 0) {
                return $this->maybeCompress($path, $compress);
            }
            // Fall through to PHP dump on failure
            @unlink($path);
        }

        // PHP logical dump fallback
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $sql = "-- SiroPHP MySQL backup\n-- Database: {$database}\n-- Date: " . date('c') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $tablesStmt = $pdo->query('SHOW TABLES');
        $tables = $tablesStmt !== false ? $tablesStmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        foreach ($tables as $table) {
            $t = is_scalar($table) ? (string) $table : '';
            if ($t === '') continue;
            $sql .= "DROP TABLE IF EXISTS `{$t}`;\n";
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$t}`");
            $create = $createStmt !== false ? $createStmt->fetch(\PDO::FETCH_NUM) : false;
            $sql .= (is_array($create) && isset($create[1]) && is_string($create[1])) ? $create[1] . ";\n\n" : '';
            $rowsStmt = $pdo->query("SELECT * FROM `{$t}`");
            if ($rowsStmt === false) continue;
            $rows = $rowsStmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $cols = implode(', ', array_map(fn ($c): string => '`' . (string) $c . '`', array_keys($row)));
                $vals = implode(', ', array_map(function ($v) use ($pdo): string {
                    if ($v === null) return 'NULL';
                    if (is_int($v) || is_float($v)) return (string) $v;
                    if (is_bool($v)) return $v ? '1' : '0';
                    if (is_string($v)) return $pdo->quote($v);
                    return $pdo->quote((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }, $row));
                $sql .= "INSERT INTO `{$t}` ({$cols}) VALUES ({$vals});\n";
            }
            $sql .= "\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $written = @file_put_contents($path, $sql);
        if ($written === false) {
            return ['success' => false, 'message' => 'Could not write backup file', 'path' => $path, 'size' => 0, 'compressed' => false];
        }
        return $this->maybeCompress($path, $compress);
    }

    /** @return array{success: bool, message: string, path: string, size: int, compressed: bool} */
    private function maybeCompress(string $path, bool $compress): array
    {
        if (!$compress) {
            return ['success' => true, 'message' => 'Backup completed', 'path' => $path, 'size' => (int) filesize($path), 'compressed' => false];
        }
        $gzPath = $path . '.gz';
        $gz = gzopen($gzPath, 'wb');
        if ($gz === false) {
            return ['success' => true, 'message' => 'Backup completed (compression failed)', 'path' => $path, 'size' => (int) filesize($path), 'compressed' => false];
        }
        $fp = fopen($path, 'rb');
        if ($fp !== false) {
            while (!feof($fp)) {
                $chunk = fread($fp, 8192);
                if ($chunk === false) break;
                gzwrite($gz, $chunk);
            }
            fclose($fp);
        }
        gzclose($gz);
        @unlink($path);
        return ['success' => true, 'message' => 'Backup completed', 'path' => $gzPath, 'size' => (int) filesize($gzPath), 'compressed' => true];
    }

    private function findMysqldump(): ?string
    {
        foreach (['mysqldump', 'mysqldump.exe', 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'] as $candidate) {
            $which = shell_exec('where ' . escapeshellarg($candidate) . ' 2>NUL');
            if (is_string($which) && trim($which) !== '') {
                return $candidate;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        if (!file_exists($configPath)) {
            return ['driver' => 'sqlite'];
        }
        $config = require $configPath;
        if (!is_array($config)) {
            return ['driver' => 'sqlite'];
        }
        /** @var array<string, mixed> $config */
        return $config;
    }

    /** @param array<string, mixed> $config */
    private function resolveDbPath(array $config): ?string
    {
        $database = is_string($config['database'] ?? null) ? $config['database'] : '';
        if ($database === '' || $database === ':memory:') {
            return null;
        }
        if (DIRECTORY_SEPARATOR === '/' && str_starts_with($database, '/')) {
            return $database;
        }
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Z]:\\\\/i', $database)) {
            return $database;
        }
        return rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($database, './');
    }
}
