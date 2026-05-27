<?php

declare(strict_types=1);

namespace Siro\Core;

final class RuntimeManager
{
    private readonly string $runtimeDir;
    private readonly string $binDir;

    public function __construct()
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        $this->runtimeDir = $home . DIRECTORY_SEPARATOR . '.siro' . DIRECTORY_SEPARATOR . 'runtime';
        $this->binDir = $home . DIRECTORY_SEPARATOR . '.siro' . DIRECTORY_SEPARATOR . 'bin';
    }

    public function runtimeDir(): string { return $this->runtimeDir; }
    public function binDir(): string { return $this->binDir; }

    /** @return array{success: bool, message: string, dir?: string} */
    public function install(string $version): array
    {
        $phpVersion = self::resolveVersion($version);
        $targetDir = $this->runtimeDir . DIRECTORY_SEPARATOR . $phpVersion;

        if (is_dir($targetDir)) {
            return ['success' => true, 'message' => "Siro Runtime {$phpVersion} already installed", 'dir' => $targetDir];
        }

        $url = self::getDownloadUrl(PHP_OS_FAMILY, $phpVersion);
        if ($url === null) {
            return ['success' => false, 'message' => "Auto-install not yet supported on " . PHP_OS_FAMILY . ". Install PHP {$version} manually."];
        }

        $zipFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "siro-php-{$phpVersion}.zip";
        Logger::debug("Downloading PHP {$phpVersion} from {$url}");

        $success = self::download($url, $zipFile);
        if (!$success) {
            @unlink($zipFile);
            return ['success' => false, 'message' => "Download failed from {$url}"];
        }

        $extracted = self::extract($zipFile, $targetDir);
        @unlink($zipFile);
        if (!$extracted) {
            return ['success' => false, 'message' => 'Extraction failed'];
        }

        self::writePhpIni($targetDir);
        $this->setActive($phpVersion);
        $this->linkBinary($targetDir);

        return ['success' => true, 'message' => "Siro Runtime {$phpVersion} installed", 'dir' => $targetDir];
    }

    /** @return array{success: bool, message: string} */
    public function switch(string $version): array
    {
        $phpVersion = self::resolveVersion($version);
        $targetDir = $this->runtimeDir . DIRECTORY_SEPARATOR . $phpVersion;

        if (!is_dir($targetDir)) {
            return ['success' => false, 'message' => "Siro Runtime {$phpVersion} not installed. Run: siro runtime:install {$version}"];
        }

        $this->setActive($phpVersion);
        $this->linkBinary($targetDir);
        return ['success' => true, 'message' => "Switched to Siro Runtime {$phpVersion}"];
    }

    /** @return array<int, array{version: string, active: bool, php_version: string, path: string}> */
    public function listVersions(): array
    {
        if (!is_dir($this->runtimeDir)) {
            return [];
        }

        $active = $this->getActive();
        $versions = [];
        $dirs = glob($this->runtimeDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if ($dirs === false) return [];

        foreach ($dirs as $dir) {
            $dir = (string) $dir;
            $ver = basename($dir);
            $phpBin = $dir . DIRECTORY_SEPARATOR . 'php.exe';
            if (!is_file($phpBin)) {
                $phpBin = $dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php';
            }
            $actualVer = is_file($phpBin) ? self::getPhpVersion($phpBin) : '?';
            $versions[] = [
                'version' => $ver,
                'active' => $ver === $active,
                'php_version' => $actualVer,
                'path' => $dir,
            ];
        }

        return $versions;
    }

    /** @return array{success: bool, message: string} */
    public function remove(string $version): array
    {
        $phpVersion = self::resolveVersion($version);
        $targetDir = $this->runtimeDir . DIRECTORY_SEPARATOR . $phpVersion;

        if (!is_dir($targetDir)) {
            return ['success' => false, 'message' => "Siro Runtime {$phpVersion} not installed"];
        }

        self::rmdirRecursive($targetDir);
        $active = $this->getActive();
        if ($active === $phpVersion) {
            $versions = $this->listVersions();
            if ($versions !== []) {
                $this->setActive($versions[0]['version']);
            } else {
                @unlink($this->runtimeDir . DIRECTORY_SEPARATOR . 'current');
            }
        }

        return ['success' => true, 'message' => "Siro Runtime {$phpVersion} removed"];
    }

    public function getActive(): string
    {
        $file = $this->runtimeDir . DIRECTORY_SEPARATOR . 'current';
        if (!is_file($file)) {
            return '';
        }
        $content = file_get_contents($file);
        return $content !== false ? trim($content) : '';
    }

    public function currentPhpBinary(): string
    {
        $active = $this->getActive();
        if ($active === '') {
            return PHP_BINARY;
        }

        $candidates = [
            $this->runtimeDir . DIRECTORY_SEPARATOR . $active . DIRECTORY_SEPARATOR . 'php.exe',
            $this->runtimeDir . DIRECTORY_SEPARATOR . $active . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return PHP_BINARY;
    }

    private function setActive(string $version): void
    {
        if (!is_dir($this->runtimeDir)) {
            mkdir($this->runtimeDir, 0755, true);
        }
        file_put_contents($this->runtimeDir . DIRECTORY_SEPARATOR . 'current', $version);
    }

    private function linkBinary(string $targetDir): void
    {
        if (!is_dir($this->binDir)) {
            mkdir($this->binDir, 0755, true);
        }

        $phpSrc = null;
        $candidates = [
            $targetDir . DIRECTORY_SEPARATOR . 'php.exe',
            $targetDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                $phpSrc = $c;
                break;
            }
        }
        if ($phpSrc === null) return;

        $this->createSiroWrapper();
        $this->createPhpWrapper($phpSrc);
    }

    private function createSiroWrapper(): void
    {
        $batContent = "@echo off\r\nphp \"%~dp0..\\runtime\\current.bat\" \"%~dp0..\\siro.phar\" %*\r\n";
        file_put_contents($this->binDir . DIRECTORY_SEPARATOR . 'siro.bat', $batContent);

        $shContent = "#!/bin/sh\nexec php \"$(dirname \"$0\")/../runtime/current.sh\" \"$(dirname \"$0\")/../siro.phar\" \"$@\"\n";
        file_put_contents($this->binDir . DIRECTORY_SEPARATOR . 'siro', $shContent);
        @chmod($this->binDir . DIRECTORY_SEPARATOR . 'siro', 0755);
    }

    private function createPhpWrapper(string $phpSrc): void
    {
        $batContent = "@echo off\r\n\"{$phpSrc}\" %*\r\n";
        file_put_contents($this->runtimeDir . DIRECTORY_SEPARATOR . 'current.bat', $batContent);

        $shContent = "#!/bin/sh\nexec \"{$phpSrc}\" \"$@\"\n";
        file_put_contents($this->runtimeDir . DIRECTORY_SEPARATOR . 'current.sh', $shContent);
        @chmod($this->runtimeDir . DIRECTORY_SEPARATOR . 'current.sh', 0755);
    }

    private static function resolveVersion(string $input): string
    {
        return match ($input) {
            '8.1' => '8.1.29',
            '8.2' => '8.2.30',
            '8.3' => '8.3.10',
            '8.4' => '8.4.0',
            default => $input,
        };
    }

    private static function getDownloadUrl(string $os, string $version): ?string
    {
        return match ($os) {
            'Windows' => sprintf(
                'https://windows.php.net/downloads/releases/php-%s-Win32-vs16-x64.zip',
                $version
            ),
            default => null,
        };
    }

    private static function download(string $url, string $dest): bool
    {
        $fp = fopen($dest, 'w+');
        if ($fp === false) return false;

        $ch = curl_init($url);
        if ($ch === false) { fclose($fp); return false; }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        return $httpCode === 200;
    }

    private static function extract(string $zipFile, string $targetDir): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) return false;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $zip->extractTo($targetDir);
        $zip->close();
        return true;
    }

    private static function writePhpIni(string $targetDir): void
    {
        $ini = implode("\r\n", [
            '[PHP]',
            'extension_dir = "ext"',
            'extension=openssl',
            'extension=pdo_mysql',
            'extension=mbstring',
            'extension=curl',
            'extension=fileinfo',
            'zend_extension=opcache',
            'opcache.enable=1',
            'opcache.enable_cli=1',
            'date.timezone=UTC',
            'memory_limit=256M',
            'max_execution_time=300',
        ]);
        file_put_contents($targetDir . DIRECTORY_SEPARATOR . 'php.ini', $ini);
    }

    private static function getPhpVersion(string $phpBin): string
    {
        $output = @shell_exec("\"{$phpBin}\" -r \"echo PHP_VERSION;\" 2>&1");
        return is_string($output) ? trim($output) : '?';
    }

    private static function rmdirRecursive(string $dir): void
    {
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? "rmdir /s /q " . escapeshellarg($dir) . " 2>NUL"
            : "rm -rf " . escapeshellarg($dir) . " 2>/dev/null";
        @shell_exec($cmd);
    }

    // ── MariaDB Portable ─────────────────────────────

    private const MARIA_VERSION = '11.4.2';
    private const MARIA_DIR = 'mariadb-11.4';

    private function mariaDir(): string
    {
        return $this->runtimeDir . DIRECTORY_SEPARATOR . self::MARIA_DIR;
    }

    private function dbActiveFile(): string
    {
        return $this->runtimeDir . DIRECTORY_SEPARATOR . 'db_active.json';
    }

    /** @return array{success: bool, message: string, port?: string, dir?: string} */
    public function installMariaDB(): array
    {
        $targetDir = $this->mariaDir();

        if (is_dir($targetDir)) {
            return $this->startMariaDB();
        }

        $os = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            $url = sprintf(
                'https://archive.mariadb.org/mariadb-%s/winx64-packages/mariadb-%s-winx64.zip',
                self::MARIA_VERSION,
                self::MARIA_VERSION
            );
            $zipFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mariadb.zip';

            echo "  Downloading MariaDB...";
            $success = self::download($url, $zipFile);
            if (!$success) {
                @unlink($zipFile);
                return ['success' => false, 'message' => "Download failed. Install MariaDB manually."];
            }

            echo " Extracting...";
            $extracted = self::extractMariaZip($zipFile, $targetDir);
            @unlink($zipFile);
            if (!$extracted) {
                return ['success' => false, 'message' => 'Extraction failed'];
            }

            // Initialize database
            echo " Initializing...";
            $dataDir = $targetDir . DIRECTORY_SEPARATOR . 'data';
            if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);

            $installCmd = "\"{$targetDir}\\" . self::findMariaBin($targetDir, 'mysql_install_db')
                . "\" --datadir=" . escapeshellarg($dataDir) . " 2>&1";
            @shell_exec($installCmd);

            echo " Starting...";
            $port = self::findFreePort();
            $result = $this->startProcess($targetDir, $dataDir, $port);
            if (!$result['success']) {
                return $result;
            }

            // Create database
            $this->createDatabase($targetDir, $port);

            $this->saveDbActive($port, $dataDir);

            return ['success' => true, 'message' => 'MariaDB installed and started', 'port' => (string) $port];
        }

        // macOS / Linux
        $installCmd = match ($os) {
            'Darwin' => 'brew list mariadb 2>/dev/null || brew install mariadb 2>&1',
            default => 'dpkg -l mariadb-server 2>/dev/null || apt-get install -y mariadb-server 2>&1',
        };

        echo "  Installing MariaDB via package manager...";
        @shell_exec($installCmd);

        if ($os === 'Darwin') {
            @shell_exec('brew services start mariadb 2>&1');
        } else {
            @shell_exec('service mariadb start 2>&1 || systemctl start mariadb 2>&1');
        }

        // Create database
        @shell_exec('mysql -u root -e "CREATE DATABASE IF NOT EXISTS siro_dev" 2>&1');

        return ['success' => true, 'message' => 'MariaDB ready', 'port' => '3306'];
    }

    /** @return array{success: bool, message: string} */
    public function startMariaDB(): array
    {
        $active = $this->readDbActive();
        if ($active !== null && $this->isPortOpen($active['port'])) {
            return ['success' => true, 'message' => "MariaDB already running on port {$active['port']}"];
        }

        $targetDir = $this->mariaDir();
        if (!is_dir($targetDir)) {
            return ['success' => false, 'message' => 'MariaDB not installed. Run: siro db:init --mysql'];
        }

        $dataDir = $targetDir . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $port = $active['port'] ?? self::findFreePort();
        $result = $this->startProcess($targetDir, $dataDir, $port);
        if (!$result['success']) {
            return $result;
        }

        $this->saveDbActive($port, $dataDir);
        return ['success' => true, 'message' => "MariaDB started on port {$port}"];
    }

    /** @return array{success: bool, message: string} */
    public function stopMariaDB(): array
    {
        $active = $this->readDbActive();
        if ($active === null) {
            return ['success' => false, 'message' => 'MariaDB not running'];
        }

        if ($active['pid'] > 0) {
            PHP_OS_FAMILY === 'Windows'
                ? @shell_exec("taskkill /PID {$active['pid']} /F 2>NUL")
                : @shell_exec("kill {$active['pid']} 2>/dev/null");
        }

        @unlink($this->dbActiveFile());
        return ['success' => true, 'message' => 'MariaDB stopped'];
    }

    /** @return array{installed: bool, running: bool, port: int, pid: int|null, datadir: string} */
    public function dbStatus(): array
    {
        $targetDir = $this->mariaDir();
        $active = $this->readDbActive();

        $installed = is_dir($targetDir);
        $running = $active !== null && $this->isPortOpen($active['port']);

        return [
            'installed' => $installed,
            'running' => $running,
            'port' => $active['port'] ?? 3306,
            'pid' => $active['pid'] ?? null,
            'datadir' => ($active['datadir'] ?? '') ?: ($targetDir . DIRECTORY_SEPARATOR . 'data'),
        ];
    }

    /** @return array{success: bool, message: string} */
    public function removeMariaDB(): array
    {
        $this->stopMariaDB();

        $targetDir = $this->mariaDir();
        if (is_dir($targetDir)) {
            self::rmdirRecursive($targetDir);
        }
        @unlink($this->dbActiveFile());

        return ['success' => true, 'message' => 'MariaDB removed'];
    }

    /** @return array{success: bool, message: string} */
    private function startProcess(string $targetDir, string $dataDir, int $port): array
    {
        $binPath = self::findMariaBin($targetDir, 'mysqld');
        if ($binPath === null) {
            return ['success' => false, 'message' => 'mysqld not found in MariaDB runtime'];
        }

        $iniPath = $targetDir . DIRECTORY_SEPARATOR . 'my.ini';
        $iniContent = "[mysqld]\r\nport={$port}\r\ndatadir={$dataDir}\r\nskip-grant-tables\r\n";
        file_put_contents($iniPath, $iniContent);

        $pidFile = $dataDir . DIRECTORY_SEPARATOR . 'siro.pid';
        $logFile = $dataDir . DIRECTORY_SEPARATOR . 'siro.log';

        $cmd = "\"{$binPath}\" --defaults-file=\"{$iniPath}\" --pid-file=\"{$pidFile}\" --log-error=\"{$logFile}\"";
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "start /B {$cmd}";
        } else {
            $cmd .= " > /dev/null 2>&1 &";
        }
        @shell_exec($cmd);

        // Wait for port
        $maxWait = 15;
        for ($i = 0; $i < $maxWait; $i++) {
            if ($this->isPortOpen($port)) {
                return ['success' => true, 'message' => "Started on port {$port}"];
            }
            sleep(1);
        }

        // Check log for errors
        $log = is_file($logFile) ? file_get_contents($logFile) : '';
        $errorMsg = $log !== false ? substr($log, -500) : 'unknown error';
        return ['success' => false, 'message' => "Failed to start MariaDB: {$errorMsg}"];
    }

    private function createDatabase(string $targetDir, int $port): void
    {
        $bin = self::findMariaBin($targetDir, 'mysql');
        if ($bin === null) return;

        $cmd = "\"{$bin}\" -h 127.0.0.1 -P {$port} -u root -e \"CREATE DATABASE IF NOT EXISTS siro_dev\" 2>&1";
        @shell_exec($cmd);
    }

    private function saveDbActive(int $port, string $datadir): void
    {
        $data = json_encode([
            'port' => $port,
            'datadir' => $datadir,
            'pid' => getmypid(),
            'started_at' => date('c'),
        ]);
        if ($data !== false) {
            file_put_contents($this->dbActiveFile(), $data);
        }
    }

    /** @return array{port: int, datadir: string, pid: int}|null */
    private function readDbActive(): ?array
    {
        $file = $this->dbActiveFile();
        if (!is_file($file)) return null;

        $content = file_get_contents($file);
        if ($content === false) return null;

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) return null;
        /** @var array<string, mixed> $data */
        $data = $decoded;

        $portVal = is_numeric($data['port'] ?? null) ? (int) $data['port'] : 3306;
        $dirVal = is_string($data['datadir'] ?? null) ? $data['datadir'] : '';
        $pidVal = is_numeric($data['pid'] ?? null) ? (int) $data['pid'] : 0;
        return [
            'port' => $portVal,
            'datadir' => $dirVal,
            'pid' => $pidVal,
        ];
    }

    private static function findMariaBin(string $targetDir, string $name): ?string
    {
        $candidates = [
            $targetDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name . '.exe',
            $targetDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name,
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function extractMariaZip(string $zipFile, string $targetDir): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) return false;

        // MariaDB ZIP has a top-level dir like "mariadb-11.4.2-winx64/"
        // We extract and rename to our target
        $tmpDir = dirname($targetDir) . DIRECTORY_SEPARATOR . '.mariadb_tmp';
        if (is_dir($tmpDir)) self::rmdirRecursive($tmpDir);
        mkdir($tmpDir, 0755, true);

        $zip->extractTo($tmpDir);
        $zip->close();

        // Find the extracted dir
        $items = scandir($tmpDir);
        if ($items === false) { self::rmdirRecursive($tmpDir); return false; }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $extractedDir = $tmpDir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($extractedDir)) {
                rename($extractedDir, $targetDir);
                self::rmdirRecursive($tmpDir);
                return true;
            }
        }

        self::rmdirRecursive($tmpDir);
        return false;
    }

    private static function findFreePort(): int
    {
        for ($port = 3306; $port <= 3400; $port++) {
            $conn = @fsockopen('127.0.0.1', $port, $_, $_, 0.1);
            if ($conn === false) {
                return $port;
            }
            fclose($conn);
        }
        return 0;
    }

    private static function isPortOpen(int $port): bool
    {
        $conn = @fsockopen('127.0.0.1', $port, $_, $_, 0.5);
        if ($conn !== false) {
            fclose($conn);
            return true;
        }
        return false;
    }
}
