<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use PDO;
use Siro\Core\DB\QueryBuilder;

interface DatabaseInterface
{
    public function configure(array $config, string $name = 'default'): void;
    public function default(string $name): void;
    public function connection(?string $name = null): PDO;
    public function purge(?string $name = null): void;
    public function purgeAll(): void;
    public function connections(): array;
    public function getCapturedQueries(): array;
    public function resetCapturedQueries(): void;

    /** @param array<int|string, mixed> $params */
    public function select(string $sql, array $params = [], ?string $connection = null): array;

    /** @param array<int|string, mixed> $params */
    public function first(string $sql, array $params = [], ?string $connection = null): ?array;

    /** @param array<int|string, mixed> $params */
    public function execute(string $sql, array $params = [], ?string $connection = null): int;

    public function cache(int $ttl = 60): static;
    public function table(string $table, ?string $connection = null): QueryBuilder;
    public function transaction(callable $callback, ?string $connection = null): mixed;

    /** @param array<int|string, mixed> $params */
    public function selectCached(string $sql, array $params, int $ttl, string $cachePrefix = 'qb:default:', ?string $connection = null): array;
}
