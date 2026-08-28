<?php

declare(strict_types=1);

namespace Siro\Core\Cache\Drivers;

/**
 * Redis cache driver.
 *
 * Uses the \Redis extension with SETEX for atomic set-with-expiry
 * and SCAN for prefix-based cache invalidation.
 *
 * @package Siro\Core\Cache\Drivers
 */
final class RedisDriver
{
    public function __construct(private readonly \Redis $redis)
    {
    }

    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);
        if ($value === false || $value === null) {
            return null;
        }

        $decoded = json_decode(is_string($value) ? $value : '', true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        $payload = json_encode([
            'value' => $value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return false;
        }

        return (bool) $this->redis->setex($key, max(1, $ttl), $payload);
    }

    public function forget(string $key): bool
    {
        return $this->redis->del($key) > 0;
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($key) > 0;
    }

    public function flush(string $prefix = ''): int
    {
        if ($prefix === '') {
            $size = (int) $this->redis->dbSize();
            $this->redis->flushDB();
            return $size;
        }

        $deleted = 0;
        $iterator = null;

        do {
            $keys = $this->redis->scan($iterator, $prefix . '*', 1000);
            if ($keys === false || $keys === []) {
                continue;
            }

            foreach ($keys as $key) {
                $deleted += $this->redis->del((string) $key);
            }
        } while ($iterator !== 0);

        return $deleted;
    }

    /**
     * Acquire a lock for a cache key using SETNX + expiry.
     *
     * Uses a unique token to prevent one process from releasing another's lock.
     */
    public function lock(string $key, int $timeoutMs = 5000): bool
    {
        $lockKey = 'lock:' . $key;
        $token = bin2hex(random_bytes(16));
        $ttlSec = max(1, (int) ceil($timeoutMs / 1000));

        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            // SETNX: atomic set-if-not-exists
            if ($this->redis->setnx($lockKey, $token)) {
                $this->redis->expire($lockKey, $ttlSec);
                $this->lockToken = $token;
                $this->lockKey = $lockKey;
                return true;
            }
            usleep(2000); // 2ms backoff
        }

        return false;
    }

    /**
     * Release lock only if we own the token.
     */
    public function unlock(string $key): void
    {
        $lockKey = 'lock:' . $key;
        $token = $this->lockToken;

        if ($token === null || $this->lockKey !== $lockKey) {
            return;
        }

        // Lua script: atomic check-and-delete
        $script = <<<LUA
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA;

        try {
            $this->redis->eval($script, [$lockKey, $token], 1);
        } catch (\Throwable) {
            // Lock will expire via TTL
        }

        $this->lockToken = null;
        $this->lockKey = null;
    }

    private ?string $lockToken = null;
    private ?string $lockKey = null;
}
