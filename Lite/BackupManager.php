<?php

declare(strict_types=1);

namespace Siro\Core\Lite;

use Throwable;

/**
 * SQLite backup and restore via VACUUM INTO.
 *
 * Creates consistent, atomic snapshots of a SQLite database file and
 * restores them by replacing the live file (with integrity validation).
 */
final class BackupManager
{
    private ?\PDO $pdo;

    public function __construct(
        \PDO $pdo,
        private readonly ?string $dbPath,
    ) {
        $this->pdo = $pdo;
    }

    /**
     * Create a snapshot of the SQLite database.
     *
     * @return array{success: bool, message: string, path: string, size: int, compressed: bool}
     */
    public function backup(string $backupDir, bool $compress): array
    {
        if ($this->dbPath === null || $this->dbPath === '') {
            return ['success' => false, 'message' => 'Cannot resolve SQLite database path', 'path' => '', 'size' => 0, 'compressed' => false];
        }
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0775, true);
        }
        if (!is_dir($backupDir)) {
            return ['success' => false, 'message' => 'Cannot create backup directory: ' . $backupDir, 'path' => '', 'size' => 0, 'compressed' => false];
        }

        $stamp = date('Y-m-d-His');
        $ext = $compress ? '.db.gz' : '.db';
        $path = $backupDir . DIRECTORY_SEPARATOR . 'backup-' . $stamp . $ext;

        try {
            if ($compress) {
                $rawPath = $backupDir . DIRECTORY_SEPARATOR . 'backup-' . $stamp . '.db';
                $this->vacuumInto($rawPath);
                $gz = gzopen($path, 'wb');
                if ($gz === false) {
                    @unlink($rawPath);
                    return ['success' => false, 'message' => 'Cannot open gzip stream', 'path' => $path, 'size' => 0, 'compressed' => true];
                }
                $fp = fopen($rawPath, 'rb');
                if ($fp === false) {
                    @unlink($rawPath);
                    return ['success' => false, 'message' => 'Cannot open raw backup for compression', 'path' => $path, 'size' => 0, 'compressed' => true];
                }
                while (!feof($fp)) {
                    $chunk = fread($fp, 8192);
                    if ($chunk === false) break;
                    gzwrite($gz, $chunk);
                }
                fclose($fp);
                gzclose($gz);
                @unlink($rawPath);
            } else {
                $this->vacuumInto($path);
            }

            $size = file_exists($path) ? (int) filesize($path) : 0;
            return ['success' => true, 'message' => 'Backup completed', 'path' => $path, 'size' => $size, 'compressed' => $compress];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Backup failed: ' . $e->getMessage(), 'path' => $path, 'size' => 0, 'compressed' => $compress];
        }
    }

    /**
     * Restore a SQLite database from a backup file.
     *
     * @return array{success: bool, message: string}
     */
    public function restore(string $backupFile): array
    {
        if ($this->dbPath === null || $this->dbPath === '') {
            return ['success' => false, 'message' => 'Cannot resolve SQLite database path'];
        }
        if (!file_exists($backupFile)) {
            return ['success' => false, 'message' => 'Backup file not found: ' . $backupFile];
        }

        $sourceFile = $backupFile;

        // Decompress .gz backups to a temp file first
        $tempFile = null;
        if (str_ends_with(strtolower($backupFile), '.gz')) {
            $tempFile = tempnam(sys_get_temp_dir(), 'siro_restore_') . '.db';
            $gz = gzopen($backupFile, 'rb');
            if ($gz === false) {
                return ['success' => false, 'message' => 'Cannot open gzip backup'];
            }
            $fp = fopen($tempFile, 'wb');
            if ($fp === false) {
                return ['success' => false, 'message' => 'Cannot create temp restore file'];
            }
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 8192);
                if ($chunk === false || $chunk === '') break;
                fwrite($fp, $chunk);
            }
            gzclose($gz);
            fclose($fp);
            $sourceFile = $tempFile;
        }

        try {
            // Validate it is a real SQLite database
            $testPdo = new \PDO('sqlite:' . $sourceFile);
            $testPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $testPdo->query('PRAGMA integrity_check');
            $testPdo = null;
        } catch (Throwable $e) {
            if ($tempFile !== null) @unlink($tempFile);
            return ['success' => false, 'message' => 'Invalid backup file: ' . $e->getMessage()];
        }

        try {
            // Release the live connection so the file can be replaced on disk
            $this->pdo = null;
            if (is_file($this->dbPath)) {
                @unlink($this->dbPath);
            }
            $copied = @copy($sourceFile, $this->dbPath);
            if ($tempFile !== null) @unlink($tempFile);
            if (!$copied) {
                return ['success' => false, 'message' => 'Could not copy backup over database: ' . $this->dbPath];
            }
            return ['success' => true, 'message' => 'Restore completed'];
        } catch (Throwable $e) {
            if ($tempFile !== null) @unlink($tempFile);
            return ['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
        }
    }

    /**
     * Create an online consistent snapshot using VACUUM INTO (SQLite >= 3.27).
     */
    private function vacuumInto(string $target): void
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is not available');
        }
        $this->pdo->exec("VACUUM INTO '" . str_replace("'", "''", $target) . "'");
    }
}
