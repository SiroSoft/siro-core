<?php

declare(strict_types=1);

namespace Siro\Core\Cache\Drivers;

interface CacheDriverInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl): bool;
    public function forget(string $key): bool;
    public function has(string $key): bool;
    public function flush(): bool;
}
