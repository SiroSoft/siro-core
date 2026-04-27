<?php

declare(strict_types=1);

namespace Siro\Core\Cache\Drivers;

final class FileDriver
{
    private readonly string $cachePath;

    public function __construct(string $cachePath)
    {
        $this->cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }
    }

    public function get(string $key): mixed
    {
        $record = $this->readRecord($key);
        if ($record === null) {
            return null;
        }

        return $record['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        $file = $this->pathFor($key);
        $payload = json_encode([
            'key' => $key,
            'expires_at' => time() + max(1, $ttl),
            'value' => $value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return false;
        }

        return file_put_contents($file, $payload, LOCK_EX) !== false;
    }

    public function forget(string $key): bool
    {
        $file = $this->pathFor($key);
        if (!is_file($file)) {
            return false;
        }

        return @unlink($file);
    }

    public function has(string $key): bool
    {
        return $this->readRecord($key) !== null;
    }

    public function flush(string $prefix = ''): int
    {
        $pattern = $this->cachePath . DIRECTORY_SEPARATOR . '*.cache';
        $files = glob($pattern) ?: [];
        $deleted = 0;

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if ($prefix !== '') {
                $content = file_get_contents($file);
                if ($content === false || $content === '') {
                    continue;
                }

                $decoded = json_decode($content, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $key = (string) ($decoded['key'] ?? '');
                if (!str_starts_with($key, $prefix)) {
                    continue;
                }
            }

            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function pathFor(string $key): string
    {
        return $this->cachePath . DIRECTORY_SEPARATOR . sha1($key) . '.cache';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRecord(string $key): ?array
    {
        $file = $this->pathFor($key);
        if (!is_file($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            @unlink($file);
            return null;
        }

        $expiresAt = (int) ($decoded['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($file);
            return null;
        }

        return $decoded;
    }
}
