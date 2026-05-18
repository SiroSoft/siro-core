<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use PDO;
use Siro\Core\DB\QueryBuilder;

interface DatabaseInterface
{
    /** @param array<string, mixed> $config */
    public function configure(array $config, string $name = 'default'): void;
    public function default(string $name): void;
    public function connection(?string $name = null): PDO;
    public function purge(?string $name = null): void;
    public function purgeAll(): void;
    /** @return array<int, string> */
    public function connections(): array;
    /** @return array<int, array{sql:string,bindings:array<int|string,mixed>,time_ms:float,rows:int,connection:string}> */
    public function getCapturedQueries(): array;
    public function resetCapturedQueries(): void;

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = [], ?string $connection = null): array;

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = [], ?string $connection = null): ?array;

    /** @param array<int|string, mixed> $params */
    public function execute(string $sql, array $params = [], ?string $connection = null): int;

    /** Execute raw SQL without prepared statement (for DDL, SET, etc.) */
    public function exec(string $sql, ?string $connection = null): int;

    public function cache(int $ttl = 60): static;
    public function table(string $table, ?string $connection = null): QueryBuilder;
    public function transaction(callable $callback, ?string $connection = null): mixed;

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function selectCached(string $sql, array $params, int $ttl, string $cachePrefix = 'qb:default:', ?string $connection = null): array;
}
