<?php

declare(strict_types=1);

namespace Siro\Core\Cache;

interface CacheInterface
{
    public function boot(string $basePath): void;
    public function reset(): void;
    public function resetRequestState(): void;
    public function requestStatus(): array;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 60): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
    public function forget(string $key): bool;
    public function has(string $key): bool;
    public function flush(string $prefix = ''): int;
    public function flushQueryBuilderTable(string $table): int;
}
