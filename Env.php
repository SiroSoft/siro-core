<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Env — Environment loader với priority chain 5 tầng.
 *
 * Siro là framework PHP duy nhất có priority chain đầy đủ:
 *   1. .env.siro          → Framework defaults (bundled, always available)
 *   2. .env               → Server/CI defaults (committed)
 *   3. .env.{APP_ENV}     → Environment-specific (e.g. .env.prod, .env.dev)
 *   4. .env.local         → Local overrides (gitignored)
 *   5. .env.{APP_ENV}.local → Env-specific local (gitignored)
 *
 * File sau ghi đè file trước. Fresh clone chạy ngay nhờ .env.siro.
 * Biến hỗ trợ interpolation: ${VAR} hoặc $VAR.
 *
 * @package Siro\Core
 */
final class Env
{
    private static bool $loaded = false;
    private static string $cachedFile = '';

    private const SENSITIVE_KEYS = [
        'APP_KEY',
        'JWT_SECRET',
        'JWT_PREVIOUS_SECRET',
        'JWT_PRIVATE_KEY',
        'JWT_PUBLIC_KEY',
        'JWT_KEY_VERSION',
        'MAIL_PASSWORD',
        'MAIL_USERNAME',
        'REDIS_PASSWORD',
        'DB_PASSWORD',
        'DB_DATABASE',
        'DB_USERNAME',
        'STORAGE_S3_KEY',
        'STORAGE_S3_SECRET',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'ENCRYPTION_KEY',
        'HASH_KEY',
        'API_KEY',
        'API_SECRET',
        'OAUTH_TOKEN',
        'OAUTH_SECRET',
        'STRIPE_KEY',
        'STRIPE_SECRET',
        'RECAPTCHA_SECRET',
        'SENDGRID_API_KEY',
        'MAILGUN_API_KEY',
        'TWILIO_AUTH_TOKEN',
        'PUSHER_APP_SECRET',
        'NEXMO_API_SECRET',
        'WEBHOOK_SECRET',
        'DEPLOY_KEY',
        'DEPLOY_SECRET',
        'SSH_PRIVATE_KEY',
        'SSH_PUBLIC_KEY',
        'PASSWORD',
        'SECRET',
        'TOKEN',
        'CREDENTIALS',
        'PRIVATE_KEY',
    ];

    /** Priority-ordered env files (highest last) */
    private const ENV_PRIORITY = [
        'siro',       // .env.siro — framework defaults
        '',           // .env — server defaults
        '{env}',      // .env.{APP_ENV} — env-specific
        'local',      // .env.local — local overrides
        '{env}.local', // .env.{APP_ENV}.local — env-specific local
    ];

    public static function load(string $filePath): void
    {
        if (self::$loaded) {
            return;
        }

        $baseDir = dirname($filePath);
        $appEnv = trim((string) self::get('APP_ENV', ''));

        // Load .env.siro from framework root first (always available)
        $frameworkSiro = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env.siro';
        if (is_file($frameworkSiro)) {
            self::parseEnvFile($frameworkSiro);
        }

        // Check cache (based on .env mtime if exists)
        $mainFile = $filePath;
        if (is_file($mainFile)) {
            self::$cachedFile = $baseDir . DIRECTORY_SEPARATOR
                . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'env.php';
            $useCache = false;
            if (is_file(self::$cachedFile)) {
                $cacheMtime = filemtime(self::$cachedFile);
                $envMtime = filemtime($mainFile);
                $useCache = $cacheMtime !== false && $envMtime !== false && $cacheMtime >= $envMtime;
            }
            if ($useCache && is_file(self::$cachedFile)) {
                $raw = substr((string) file_get_contents(self::$cachedFile), strlen('<?php exit; ?>'));
                $cached = null;
                $appKey = (string) self::get('APP_KEY', '');
                if ($appKey !== '' && strlen($appKey) >= 16 && class_exists(\Siro\Core\Encrypter::class)) {
                    try {
                        $decrypted = \Siro\Core\Encrypter::decrypt($raw, $appKey);
                        $cached = json_decode($decrypted, true);
                    } catch (\Throwable) {
                        $cached = null;
                    }
                } elseif ($appKey === '' || strlen($appKey) < 16) {
                    throw new \RuntimeException('APP_KEY is required (min 16 chars) for env cache integrity. Set a strong APP_KEY in .env.');
                } else {
                    $cached = null;
                }
                if (is_array($cached)) {
                    foreach ($cached as $key => $value) {
                        $strKey = (string) $key;
                        $strValue = is_scalar($value) ? (string) $value : '';
                        $_ENV[$strKey] = $strValue;
                        $_SERVER[$strKey] = $strValue;
                        putenv($strKey . '=' . $strValue);
                    }
                    // Re-parse .env for sensitive keys excluded from cache
                    if (is_file($mainFile)) {
                        self::parseEnvFile($mainFile);
                    }
                    self::$loaded = true;
                    return;
                }
            }
        }

        // === Priority chain: load từng file, file sau ghi đè file trước ===
        $loadedAny = false;
        foreach (self::ENV_PRIORITY as $suffix) {
            $resolved = str_replace('{env}', $appEnv, $suffix);
            $envFile = $resolved === ''
                ? $filePath
                : $baseDir . DIRECTORY_SEPARATOR . '.env.' . $resolved;

            if (is_file($envFile)) {
                self::parseEnvFile($envFile);
                $loadedAny = true;
            }
        }

        self::$loaded = true;

        // Nếu không load được file nào, log warning (ko crash)
        if (!$loadedAny) {
            trigger_error(
                'SIRO_ENV: No .env file found. Create .env or .env.local in project root.',
                E_USER_NOTICE
            );
        }
    }

    /**
     * Parse a .env file: supports quotes, comments, variable interpolation.
     *
     * Interpolation: APP_KEY=${BASE_KEY}_suffix hoặc $BASE_KEY
     */
    private static function parseEnvFile(string $filePath): void
    {
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

            $useInterpolation = true;
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === "'") {
                    $useInterpolation = false;
                }
            }

            if ($useInterpolation) {
                $value = (string) preg_replace_callback(
                    '/\$\{([^}]+)\}|\$([a-zA-Z_][a-zA-Z0-9_]*)/',
                    function (array $m): string {
                        $name = $m[1] !== '' ? $m[1] : $m[2];
                        $envVal = $_ENV[$name] ?? null;
                        return is_scalar($envVal) ? (string) $envVal : '';
                    },
                    $value
                );
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv(sprintf('%s=%s', $key, $value));
        }
    }

    public static function cache(string $filePath): bool
    {
        $cacheDir = dirname($filePath) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $data = [];
        foreach ($_ENV as $key => $value) {
            if (!in_array($key, self::SENSITIVE_KEYS, true)) {
                $data[$key] = $value;
            }
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) { return false; }

        $appKey = (string) self::get('APP_KEY', '');
        if ($appKey !== '' && strlen($appKey) >= 16 && class_exists(\Siro\Core\Encrypter::class)) {
            $payload = \Siro\Core\Encrypter::encrypt($payload, $appKey);
        }

        $content = '<?php exit; ?>' . $payload . PHP_EOL;
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'env.php';
        return file_put_contents($cacheFile, $content) !== false;
    }

    public static function clearCache(string $basePath): void
    {
        $cacheFile = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'env.php';
        if (is_file($cacheFile)) {
            unlink($cacheFile);
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
            $val = $_ENV[$key];
            return is_scalar($val) ? (string) $val : $default;
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
