<?php

declare(strict_types=1);

namespace Siro\Core;

final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];
    private static bool $loaded = false;
    private static string $configPath = '';
    /** @var array<string, mixed> */
    private static array $cache = [];

    public static function load(string $configPath): void
    {
        self::$configPath = rtrim($configPath, DIRECTORY_SEPARATOR);
        self::$items = [];
        self::$cache = [];

        $cacheFile = dirname(self::$configPath) . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'config.php';

        // Check cache first - avoid reading all config files if cache exists and is fresh
        if (is_file($cacheFile)) {
            $cacheModified = filemtime($cacheFile);
            $configModified = self::getConfigDirMtime(self::$configPath);

            if ($cacheModified !== false && $configModified !== false && $cacheModified >= $configModified) {
                $cached = json_decode(substr((string) file_get_contents($cacheFile), 14), true);
                if (is_array($cached)) {
                    self::$items = $cached;
                    self::$loaded = true;
                    return;
                }
            }
        }

        if (!is_dir(self::$configPath)) {
            self::$loaded = true;
            return;
        }

        $files = glob(self::$configPath . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            self::$loaded = true;
            return;
        }

        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $config = require $file;
            if (is_array($config)) {
                self::$items[$key] = $config;
            }
        }

        self::$loaded = true;
    }

    private static function getConfigDirMtime(string $configPath): int|false
    {
        $mtime = false;
        $files = glob($configPath . DIRECTORY_SEPARATOR . '*.php');
        if ($files !== false) {
            foreach ($files as $file) {
                $fileMtime = filemtime($file);
                if ($fileMtime !== false && ($mtime === false || $fileMtime > $mtime)) {
                    $mtime = $fileMtime;
                }
            }
        }
        return $mtime;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        self::$cache[$key] = $value;
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &self::$items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $target[$segment] = $value;
            } else {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = [];
                }
                $target = &$target[$segment];
            }
        }

        unset($target);
        self::$cache = [];
    }

    public static function has(string $key): bool
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::$items;
    }

    public static function cache(): ?string
    {
        $cacheDir = dirname(self::$configPath) . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'config.php';
        $content = '<?php exit; ?>' . json_encode(self::$items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        if (file_put_contents($cacheFile, $content) !== false) {
            return $cacheFile;
        }

        return null;
    }

    public static function clearCache(): void
    {
        $cacheFile = dirname(self::$configPath) . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }
        self::$cache = [];
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    public static function reset(): void
    {
        self::$items = [];
        self::$cache = [];
        self::$loaded = false;
        self::$configPath = '';
    }
}
