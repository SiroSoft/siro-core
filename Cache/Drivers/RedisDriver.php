<?php

declare(strict_types=1);

namespace Siro\Core\Cache\Drivers;

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

        $decoded = json_decode((string) $value, true);
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
}
