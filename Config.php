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
    /** @var list<string>|null */
    private static ?array $cachedGlob = null;

    public static function load(string $configPath): void
    {
        self::$configPath = rtrim($configPath, DIRECTORY_SEPARATOR);
        self::$items = [];
        self::$cache = [];
        self::$cachedGlob = null;

        $secret = (string) \Siro\Core\Env::get('APP_KEY', '');
        $cacheFile = dirname(self::$configPath) . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'config.php';

        if ($secret !== '' && is_file($cacheFile)) {
            $cacheModified = filemtime($cacheFile);
            $configModified = self::getConfigDirMtime();

            if ($cacheModified !== false && $configModified !== false && $cacheModified >= $configModified) {
                $raw = (string) file_get_contents($cacheFile);
                $payload = substr($raw, strlen('<?php exit; ?>'));
                $sep = strrpos($payload, '.hmac.');
                if ($sep !== false) {
                    $data = json_decode(substr($payload, 0, $sep), true);
                    $hmac = trim(substr($payload, $sep + 6));
                    $expected = hash_hmac('sha256', substr($payload, 0, $sep), $secret);
                    if (is_array($data) && hash_equals($expected, $hmac)) {
                        /** @var array<string, mixed> $data */
                        self::$items = $data;
                        self::$loaded = true;
                        return;
                    }
                }
            }
        }

        if (!is_dir(self::$configPath)) {
            self::$loaded = true;
            return;
        }

        $files = self::getConfigFiles();
        if ($files === null) {
            self::$loaded = true;
            return;
        }

        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $config = require $file;
            if ($secret !== '') {
                $fileContent = (string) file_get_contents($file);
                $fileHash = hash_hmac('sha256', $fileContent, $secret);
                self::$items['_integrity'][(string) $key] = $fileHash;
            }
            if (is_array($config)) {
                /** @var array<string, mixed> $config */
                self::$items[(string) $key] = $config;
            }
        }

        self::$loaded = true;
    }

    /** @return list<string>|null */
    private static function getConfigFiles(): array|null
    {
        if (self::$cachedGlob !== null) {
            return self::$cachedGlob;
        }
        /** @var list<string>|false $files */
        $files = glob(self::$configPath . DIRECTORY_SEPARATOR . '*.php');
        self::$cachedGlob = $files !== false ? $files : null;
        return self::$cachedGlob;
    }

    private static function getConfigDirMtime(): int|false
    {
        $mtime = false;
        $files = self::getConfigFiles();
        if ($files !== null) {
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
        $secret = (string) \Siro\Core\Env::get('APP_KEY', '');
        if ($secret === '') {
            return null;
        }

        $cacheDir = dirname(self::$configPath) . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'config.php';
        // Serialize config to a JSON + HMAC payload for tamper-proof caching.
        // The HMAC is verified on load (see load() method) to ensure cache integrity.
        $json = json_encode(self::$items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { return null; }
        $hmac = hash_hmac('sha256', $json, $secret);
        $content = '<?php exit; ?>' . $json . '.hmac.' . $hmac . PHP_EOL;

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
