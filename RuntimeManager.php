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
}
