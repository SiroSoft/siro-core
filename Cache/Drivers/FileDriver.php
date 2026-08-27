<?php

declare(strict_types=1);

namespace Siro\Core\Cache\Drivers;

/**
 * File-based cache driver.
 *
 * Stores cache entries as JSON files with TTL expiration.
 * Uses LOCK_EX for concurrent write safety and SHA1-based
 * filenames to avoid collisions.
 *
 * @package Siro\Core\Cache\Drivers
 */
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
        $expiresAt = $ttl === 0 ? 0 : time() + $ttl;
        $payload = json_encode([
            'key' => $key,
            'expires_at' => $expiresAt,
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
                $filename = basename($file);
                $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $prefix);
                $safePrefix = substr((string) $sanitized, 0, 200);
                if (!str_starts_with($filename, $safePrefix)) {
                    continue;
                }
            }

            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @var array<string, resource> Open lock file handles, keyed by normalized key. */
    private array $lockHandles = [];

    /**
     * Acquire a lock for a cache key.
     *
     * Uses flock on a dedicated lock file. Returns true if lock acquired,
     * false if another process holds it. Lock auto-expires via TTL.
     */
    public function lock(string $key, int $timeoutMs = 5000): bool
    {
        $lockFile = $this->lockPathFor($key);
        $fp = @fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);

        // Try to acquire lock with bounded wait
        while (microtime(true) < $deadline) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                // Track this handle so unlock() uses the same one
                $this->lockHandles[$key] = $fp;
                return true;
            }
            usleep(2000); // 2ms backoff
        }

        fclose($fp);
        return false;
    }

    /**
     * Release a previously acquired lock using the original handle.
     */
    public function unlock(string $key): void
    {
        if (!isset($this->lockHandles[$key])) {
            return;
        }

        $fp = $this->lockHandles[$key];
        flock($fp, LOCK_UN);
        fclose($fp);
        unset($this->lockHandles[$key]);

        $lockFile = $this->lockPathFor($key);
        @unlink($lockFile);
    }

    private function lockPathFor(string $key): string
    {
        $safe = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        $safe = substr($safe, 0, 200) . '_' . sha1($key);
        return $this->cachePath . DIRECTORY_SEPARATOR . $safe . '.lock';
    }

    private function pathFor(string $key): string
    {
        $safe = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        $safe = substr($safe, 0, 200) . '_' . sha1($key);
        return $this->cachePath . DIRECTORY_SEPARATOR . $safe . '.cache';
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

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            @unlink($file);
            return null;
        }
        /** @var array<string, string|int|float|bool|null> $decoded */

        $expiresAt = (int) ($decoded['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            @unlink($file);
            return null;
        }

        return $decoded;
    }
}
