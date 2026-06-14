<?php

declare(strict_types=1);

namespace Siro\Core\Queue;

use Siro\Core\Cache\CacheInstance;

final class RedisQueueDriver
{
    private const KEY_PREFIX = 'siro:queue:';
    private const DEFAULT_TIMEOUT = 2;

    private ?\Redis $redis = null;

    public function __construct()
    {
        $this->redis = CacheInstance::getRedisConnection();
    }

    public function isAvailable(): bool
    {
        return $this->redis !== null;
    }

    public function push(string $queue, string $payload): void
    {
        if ($this->redis === null) { return; }
        $this->redis->lPush(self::KEY_PREFIX . $queue, $payload);
    }

    public function pop(string $queue, int $timeout = self::DEFAULT_TIMEOUT): ?string
    {
        if ($this->redis === null) { return null; }
        $result = $this->redis->brPop(self::KEY_PREFIX . $queue, $timeout);
        if (!is_array($result) || count($result) < 2) {
            return null;
        }
        $val = $result[1];
        return is_string($val) ? $val : null;
    }

    public function release(string $queue, string $payload, int $delay): void
    {
        if ($this->redis === null) { return; }
        $this->redis->zAdd(self::KEY_PREFIX . $queue . ':delayed', time() + $delay, $payload);
    }

    public function delete(string $queue, string $payload): void
    {
        if ($this->redis === null) { return; }
        $this->redis->lRem(self::KEY_PREFIX . $queue, $payload, 0);
    }

    public function count(string $queue): int
    {
        if ($this->redis === null) { return 0; }
        return $this->redis->lLen(self::KEY_PREFIX . $queue);
    }

    public function migrateDelayed(string $queue): int
    {
        if ($this->redis === null) { return 0; }
        $now = time();
        $key = self::KEY_PREFIX . $queue . ':delayed';
        $jobs = $this->redis->zRangeByScore($key, '0', (string) $now);
        if (!is_array($jobs) || $jobs === []) {
            return 0;
        }

        $count = count($jobs);
        foreach ($jobs as $job) {
            $this->redis->rPush(self::KEY_PREFIX . $queue, $job);
        }
        $this->redis->zRemRangeByScore($key, '0', (string) $now);
        return $count;
    }
}
