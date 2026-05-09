<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Filesystem storage abstraction.
 *
 * Supports local filesystem and S3-compatible object storage.
 * Config via .env:
 *   STORAGE_DRIVER=local|s3
 *   STORAGE_PATH=storage/app          (local)
 *   STORAGE_S3_KEY=your-key           (S3)
 *   STORAGE_S3_SECRET=your-secret     (S3)
 *   STORAGE_S3_REGION=us-east-1       (S3)
 *   STORAGE_S3_BUCKET=my-bucket       (S3)
 *   STORAGE_S3_ENDPOINT=              (S3-compatible, optional)
 *
 * Usage:
 *   Storage::put('file.txt', 'content');
 *   Storage::get('file.txt');
 *   Storage::delete('file.txt');
 *   Storage::exists('file.txt');
 *   Storage::url('file.txt');
 *
 * @package Siro\Core
 */
final class Storage
{
    private const LOCAL = 'local';
    private const S3 = 's3';
    private static bool $faked = false;
    /** @var array<string, string> */
    private static array $fakeFiles = [];

    public static function fake(): void
    {
        self::$faked = true;
        self::$fakeFiles = [];
    }

    public static function assertExists(string $path): void
    {
        \PHPUnit\Framework\Assert::assertArrayHasKey($path, self::$fakeFiles, "File '{$path}' was not stored.");
    }

    public static function assertMissing(string $path): void
    {
        \PHPUnit\Framework\Assert::assertArrayNotHasKey($path, self::$fakeFiles, "File '{$path}' was stored unexpectedly.");
    }

    /** @var array<string, mixed> */
    private static array $config = [];
    private static string $driver = self::LOCAL;

    /**
     * Initialize storage configuration.
     */
    public static function boot(): void
    {
        self::$driver = strtolower((string) Env::get('STORAGE_DRIVER', self::LOCAL));
        self::$config = [
            'path' => (string) Env::get('STORAGE_PATH', 'storage/app'),
            's3' => [
                'key' => (string) Env::get('STORAGE_S3_KEY', ''),
                'secret' => (string) Env::get('STORAGE_S3_SECRET', ''),
                'region' => (string) Env::get('STORAGE_S3_REGION', 'us-east-1'),
                'bucket' => (string) Env::get('STORAGE_S3_BUCKET', ''),
                'endpoint' => (string) Env::get('STORAGE_S3_ENDPOINT', ''),
            ],
        ];
    }

    /**
     * Write content to a file.
     */
    public static function put(string $path, string $content): bool
    {
        return match (self::$driver) {
            self::S3 => self::s3Put($path, $content),
            default => self::localPut($path, $content),
        };
    }

    /**
     * Read content from a file.
     */
    public static function get(string $path): ?string
    {
        return match (self::$driver) {
            self::S3 => self::s3Get($path),
            default => self::localGet($path),
        };
    }

    /**
     * Delete a file.
     */
    public static function delete(string $path): bool
    {
        return match (self::$driver) {
            self::S3 => self::s3Delete($path),
            default => self::localDelete($path),
        };
    }

    /**
     * Check if a file exists.
     */
    public static function exists(string $path): bool
    {
        return match (self::$driver) {
            self::S3 => self::s3Exists($path),
            default => self::localExists($path),
        };
    }

    /**
     * Get the public URL for a file.
     */
    public static function url(string $path): string
    {
        return match (self::$driver) {
            self::S3 => self::s3Url($path),
            default => self::localUrl($path),
        };
    }

    /**
     * Get all files in a directory (local only).
     *
     * @return array<int, string>
     */
    public static function files(string $directory = ''): array
    {
        if (self::$driver === self::S3) {
            throw new RuntimeException('Storage::files() is not supported for S3 driver.');
        }

        return self::localFiles($directory);
    }

    /**
     * Store an uploaded file with a unique filename.
     */
    public static function putFile(string $path, string $content, ?string $filename = null): string
    {
        if ($filename === null) {
            $filename = bin2hex(random_bytes(16)) . '.' . pathinfo($path, PATHINFO_EXTENSION);
        }

        return self::put($path . '/' . $filename, $content) ? $path . '/' . $filename : '';
    }

    /**
     * Copy a file from one location to another within storage.
     */
    public static function copy(string $source, string $dest): bool
    {
        $content = self::get($source);
        if ($content === null) {
            return false;
        }

        return self::put($dest, $content);
    }

    /**
     * Get file size.
     */
    public static function size(string $path): int
    {
        if (self::$driver === self::S3) {
            throw new RuntimeException('Storage::size() is not supported for S3 driver.');
        }

        $fullPath = self::localPath($path);
        return is_file($fullPath) ? (int) filesize($fullPath) : 0;
    }

    /**
     * Get file last modified time.
     */
    public static function lastModified(string $path): int
    {
        if (self::$driver === self::S3) {
            throw new RuntimeException('Storage::lastModified() is not supported for S3 driver.');
        }

        $fullPath = self::localPath($path);
        return is_file($fullPath) ? (int) filemtime($fullPath) : 0;
    }

    // ─── Local Driver ─────────────────────────────────────

    public static function localPath(string $path): string
    {
        $base = defined('SIRO_BASE_PATH') ? (string) SIRO_BASE_PATH : (string) getcwd();
        $base = rtrim($base, DIRECTORY_SEPARATOR);
        $storagePath = str_replace('/', DIRECTORY_SEPARATOR, (string) (self::$config['path'] ?? ''));

        return $base . DIRECTORY_SEPARATOR . $storagePath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private static function localPut(string $path, string $content): bool
    {
        if (self::$faked) {
            self::$fakeFiles[$path] = $content;
            return true;
        }
        $fullPath = self::localPath($path);
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return file_put_contents($fullPath, $content) !== false;
    }

    private static function localGet(string $path): ?string
    {
        if (self::$faked) {
            return self::$fakeFiles[$path] ?? null;
        }
        $fullPath = self::localPath($path);
        if (!is_file($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    private static function localDelete(string $path): bool
    {
        if (self::$faked) {
            if (isset(self::$fakeFiles[$path])) {
                unset(self::$fakeFiles[$path]);
                return true;
            }
            return false;
        }
        $fullPath = self::localPath($path);
        if (!is_file($fullPath)) {
            return false;
        }

        return unlink($fullPath);
    }

    private static function localExists(string $path): bool
    {
        if (self::$faked) {
            return isset(self::$fakeFiles[$path]);
        }
        return is_file(self::localPath($path));
    }

    public static function localUrl(string $path): string
    {
        return '/storage/' . ltrim($path, '/');
    }

    /**
     * @return array<int, string>
     */
    private static function localFiles(string $directory): array
    {
        $fullPath = self::localPath($directory);
        if (!is_dir($fullPath)) {
            return [];
        }

        $files = glob(rtrim($fullPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [];
        $result = [];

        foreach ($files as $file) {
            if (is_file($file)) {
                $result[] = basename($file);
            }
        }

        return $result;
    }

    // ─── S3 Driver ────────────────────────────────────────

    private static function s3Config(): array
    {
        return self::$config['s3'];
    }

    private static function s3Put(string $path, string $content): bool
    {
        $cfg = self::s3Config();
        $url = self::s3ObjectUrl($path);
        $contentType = self::mimeType($path);

        $headers = [
            'Content-Type: ' . $contentType,
            'Content-Length: ' . strlen($content),
            'Date: ' . gmdate('D, d M Y H:i:s T'),
            'Host: ' . parse_url($url, PHP_URL_HOST),
        ];

        $auth = self::s3Sign('PUT', $path, $headers);
        $headers[] = 'Authorization: ' . $auth;

        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }

    private static function s3Get(string $path): ?string
    {
        $cfg = self::s3Config();
        $url = self::s3ObjectUrl($path);

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s T'),
            'Host: ' . parse_url($url, PHP_URL_HOST),
        ];

        $auth = self::s3Sign('GET', $path, $headers);
        $headers[] = 'Authorization: ' . $auth;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false ? $result : null;
    }

    private static function s3Delete(string $path): bool
    {
        $cfg = self::s3Config();
        $url = self::s3ObjectUrl($path);

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s T'),
            'Host: ' . parse_url($url, PHP_URL_HOST),
        ];

        $auth = self::s3Sign('DELETE', $path, $headers);
        $headers[] = 'Authorization: ' . $auth;

        $context = stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }

    private static function s3Exists(string $path): bool
    {
        $cfg = self::s3Config();
        $url = self::s3ObjectUrl($path);

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s T'),
            'Host: ' . parse_url($url, PHP_URL_HOST),
        ];

        $auth = self::s3Sign('HEAD', $path, $headers);

        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ]);

        $result = @get_headers($url, false, $context);
        return $result !== false && (str_contains($result[0] ?? '', '200') || str_contains($result[0] ?? '', '404') === false);
    }

    private static function s3Url(string $path): string
    {
        $cfg = self::s3Config();
        $bucket = $cfg['bucket'];
        $region = $cfg['region'];

        if ($cfg['endpoint'] !== '') {
            return rtrim($cfg['endpoint'], '/') . '/' . ltrim($path, '/');
        }

        return "https://{$bucket}.s3.{$region}.amazonaws.com/" . ltrim($path, '/');
    }

    private static function s3ObjectUrl(string $path): string
    {
        return self::s3Url($path);
    }

    private static function s3Sign(string $method, string $path, array $headers): string
    {
        $cfg = self::s3Config();
        $key = $cfg['key'];
        $secret = $cfg['secret'];
        $region = $cfg['region'];
        $bucket = $cfg['bucket'];
        $service = 's3';
        $algorithm = 'AWS4-HMAC-SHA256';

        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $credentialScope = "{$date}/{$region}/{$service}/aws4_request";

        $signedHeaders = [];
        $headerLines = [];
        foreach ($headers as $h) {
            $parts = explode(':', $h, 2);
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1] ?? '');
            $signedHeaders[] = $name;
            $headerLines[$name] = $value;
        }
        sort($signedHeaders);

        $canonicalUri = '/' . ltrim($path, '/');
        $canonicalQueryString = '';
        $payload = $method === 'PUT' ? 'UNSIGNED-PAYLOAD' : 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $canonicalHeaders = '';
        foreach ($signedHeaders as $name) {
            $canonicalHeaders .= $name . ':' . ($headerLines[$name] ?? '') . "\n";
        }

        $canonicalRequest = "{$method}\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n" . implode(';', $signedHeaders) . "\n{$payload}";
        $hash = hash('sha256', $canonicalRequest);

        $stringToSign = "{$algorithm}\n{$now}\n{$credentialScope}\n{$hash}";
        $signingKey = self::s3SigningKey($secret, $date, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "{$algorithm} Credential={$key}/{$credentialScope}, SignedHeaders=" . implode(';', $signedHeaders) . ", Signature={$signature}";
    }

    private static function s3SigningKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private static function mimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'html' => 'text/html',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}
