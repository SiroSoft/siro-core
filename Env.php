<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Environment variable loader and accessor.
 *
 * Parses .env files and provides typed access to environment
 * variables via get(), bool(), and related methods.
 *
 * @package Siro\Core
 */
final class Env
{
    private static bool $loaded = false;
    private static string $cachedFile = '';

    public static function load(string $filePath): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($filePath)) {
            self::$loaded = true;
            return;
        }

        // Check for cached env file
        self::$cachedFile = dirname($filePath) . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'env.php';
        if (is_file(self::$cachedFile)) {
            $cached = require self::$cachedFile;
            if (is_array($cached)) {
                foreach ($cached as $key => $value) {
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                    putenv("{$key}={$value}");
                }
                self::$loaded = true;
                // Cache excludes secrets (JWT_SECRET, APP_KEY), load them from .env
                $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, '#')) continue;
                        $pos = strpos($line, '=');
                        if ($pos === false) continue;
                        $key = trim(substr($line, 0, $pos));
                        $value = trim(substr($line, $pos + 1));
                        if ($key === 'JWT_SECRET' || $key === 'APP_KEY') {
                            if (!array_key_exists($key, $_ENV)) {
                                if (
                                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                                ) {
                                    $value = substr($value, 1, -1);
                                }
                                $_ENV[$key] = $value;
                                $_SERVER[$key] = $value;
                                putenv(sprintf('%s=%s', $key, $value));
                            }
                        }
                    }
                }
                return;
            }
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($key === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv(sprintf('%s=%s', $key, $value));
        }

        self::$loaded = true;
    }

    public static function cache(string $filePath): bool
    {
        $cacheDir = dirname($filePath) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $data = [];
        foreach ($_ENV as $key => $value) {
            $data[$key] = $value;
        }
        unset($data['APP_KEY'], $data['JWT_SECRET']);

        $export = var_export($data, true);
        $content = "<?php return {$export};" . PHP_EOL;
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'env.php';
        return file_put_contents($cacheFile, $content) !== false;
    }

    public static function clearCache(string $basePath): void
    {
        $cacheFile = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'env.php';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    public static function reset(): void
    {
        self::$loaded = false;
        self::$cachedFile = '';
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
