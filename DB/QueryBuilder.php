<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use RuntimeException;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\DB\RawExpression;

/**
 * Fluent SQL query builder.
 *
 * Builds parameterized SELECT/INSERT/UPDATE/DELETE queries with
 * support for WHERE, JOIN, GROUP BY, HAVING, ORDER BY, pagination,
 * aggregations, and query caching.
 *
 * @package Siro\Core\DB
 */
class QueryBuilder
{
    protected string $table = '';
    /** @var array<int, string> */
    protected array $columns = ['*'];
    /** @var array<int, array{type:'basic', boolean:string, column:string, operator:string, param:string}|array{type:'raw', boolean:string, sql:string, bindings?:mixed}|array{type:'in', boolean:string, column:string, not:bool, params:array<int, string>}> */
    protected array $wheres = [];
    /** @var array<int, array{boolean:string, column:string, operator:string, param:string}> */
    protected array $havings = [];
    /** @var array<int, array{type:string,table:string,first:string,operator:string,second:string}> */
    protected array $joins = [];
    /** @var array<int, string> */
    protected array $groups = [];
    /** @var array<int, array{column:string,direction:string}> */
    protected array $orders = [];
    /** @var array<string, mixed> */
    protected array $bindings = [];
    protected ?int $limitValue = null;
    protected ?int $offsetValue = null;
    protected bool $lockForUpdate = false;
    protected bool $sharedLock = false;
    protected int $whereCounter = 0;
    protected int $havingCounter = 0;
    protected int $inCounter = 0;
    protected int $cacheTtl = 0;
    protected string $cacheTable = '';
    protected ?string $connectionName = null;
    protected string $primaryKey = 'id';

    private SqlCompiler $compiler;

    private const CHUNK_SIZE = 500;

    public function __construct(string $table)
    {
        $this->compiler = new SqlCompiler();
        $this->table($table);
    }

    public function connection(?string $name): self
    {
        $this->connectionName = $name;
        $this->compiler->setConnection($name);
        return $this;
    }

    public function table(string $table): self
    {
        $this->table = trim($table);
        if ($this->table === '') {
            throw new RuntimeException('QueryBuilder table name cannot be empty.');
        }
        $this->cacheTable = $this->compiler->detectTableName($this->table);
        $this->compiler->setTable($this->table);
        return $this;
    }

    /** @param string|array<int, string> ...$columns */
    public function select(array|string ...$columns): self
    {
        if (count($columns) === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }

        $normalized = [];
        foreach ($columns as $column) {
            if (is_array($column)) {
                continue;
            }
            $column = trim($column);
            if ($column !== '') {
                $normalized[] = $column;
            }
        }

        $this->columns = $normalized === [] ? ['*'] : $normalized;
        return $this;
    }

    public function selectRaw(string $expression): self
    {
        $expression = trim($expression);
        if ($expression !== '') {
            $this->columns = [$expression];
        }
        return $this;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $hasExplicitValue = func_num_args() >= 3;
        return $this->addWhere('AND', $column, $operatorOrValue, $value, $hasExplicitValue);
    }

    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $hasExplicitValue = func_num_args() >= 3;
        return $this->addWhere('OR', $column, $operatorOrValue, $value, $hasExplicitValue);
    }

    /** @param array<int|string, mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        return $this->addWhereIn('AND', $column, $values, false);
    }

    /**
     * @param array<int|string, int|string> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'sql' => $sql,
            'bindings' => $bindings,
        ];
        return $this;
    }

    /** @param array<int|string, mixed> $values */
    public function orWhereIn(string $column, array $values): self
    {
        return $this->addWhereIn('OR', $column, $values, false);
    }

    /** @param array<int|string, mixed> $values */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->addWhereIn('AND', $column, $values, true);
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'INNER',
            'table' => trim($table),
            'first' => trim($first),
            'operator' => $this->compiler->normalizeOperator($operator),
            'second' => trim($second),
        ];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'LEFT',
            'table' => trim($table),
            'first' => trim($first),
            'operator' => $this->compiler->normalizeOperator($operator),
            'second' => trim($second),
        ];
        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'RIGHT',
            'table' => trim($table),
            'first' => trim($first),
            'operator' => $this->compiler->normalizeOperator($operator),
            'second' => trim($second),
        ];
        return $this;
    }

    public function crossJoin(string $table): self
    {
        $this->joins[] = [
            'type' => 'CROSS',
            'table' => trim($table),
            'first' => '',
            'operator' => '',
            'second' => '',
        ];
        return $this;
    }

    /** @param array<int, string>|string ...$columns */
    public function groupBy(array|string ...$columns): self
    {
        if (count($columns) === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }
        foreach ($columns as $column) {
            if (!is_string($column)) {
                continue;
            }
            $column = trim($column);
            if ($column !== '') {
                $this->groups[] = $column;
            }
        }
        return $this;
    }

    public function having(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $hasExplicitValue = func_num_args() >= 3;
        return $this->addHaving('AND', $column, $operatorOrValue, $value, $hasExplicitValue);
    }

    public function orHaving(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $hasExplicitValue = func_num_args() >= 3;
        return $this->addHaving('OR', $column, $operatorOrValue, $value, $hasExplicitValue);
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $dir = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = ['column' => trim($column), 'direction' => $dir];
        return $this;
    }

    public function orderByRaw(string $expression, string $direction = 'asc'): self
    {
        $dir = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = ['column' => trim($expression), 'direction' => $dir, 'raw' => true];
        return $this;
    }

    public static function resetDriverNames(): void
    {
        SqlCompiler::resetDriverNames();
    }

    public function limit(int $limit): self
    {
        $this->limitValue = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetValue = max(0, $offset);
        return $this;
    }

    public function cache(int $ttl = 60): self
    {
        $this->cacheTtl = max(0, $ttl);
        return $this;
    }

    public function lockForUpdate(): self
    {
        $this->lockForUpdate = true;
        $this->sharedLock = false;
        return $this;
    }

    public function sharedLock(): self
    {
        $this->sharedLock = true;
        $this->lockForUpdate = false;
        return $this;
    }

    private function resolveLockMode(): string
    {
        if (!$this->lockForUpdate && !$this->sharedLock) {
            return '';
        }
        $driver = 'mysql';
        try {
            $conn = Database::connection($this->connectionName);
            $driverAttr = $conn->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverAttr) ? $driverAttr : 'mysql';
        } catch (\Throwable) {
        }
        if ($this->lockForUpdate) {
            return match ($driver) {
                'pgsql', 'postgres', 'postgresql' => 'FOR UPDATE',
                'sqlite' => '',
                default => 'FOR UPDATE',
            };
        }
        return match ($driver) {
            'pgsql', 'postgres', 'postgresql' => 'FOR SHARE',
            'sqlite' => '',
            default => 'LOCK IN SHARE MODE',
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function get(): array
    {
        $lockMode = $this->resolveLockMode();
        [$sql, $bindings] = $this->compiler->buildSelectQuery(
            $this->columns, $this->table, $this->wheres, $this->havings,
            $this->joins, $this->groups, $this->orders,
            $this->limitValue, $this->offsetValue, $this->bindings,
            $lockMode
        );
        return $this->runSelect($sql, $bindings);
    }

    /**
     * Memory-efficient row streaming using a Generator.
     *
     * Yields one row at a time instead of loading all results into memory.
     * Ideal for processing large datasets (exports, migrations, batch jobs).
     * Skips query cache — cursor is intended for uncached iteration.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(): \Generator
    {
        $lockMode = $this->resolveLockMode();
        [$sql, $bindings] = $this->compiler->buildSelectQuery(
            $this->columns, $this->table, $this->wheres, $this->havings,
            $this->joins, $this->groups, $this->orders,
            $this->limitValue, $this->offsetValue, $this->bindings,
            $lockMode
        );

        $stmt = Database::connection($this->connectionName)->prepare($sql);
        $stmt->execute($bindings);

        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            /** @var array<string, mixed> $row */
            yield $row;
        }
    }

    /** @return array<string, mixed>|null */
    public function first(): mixed
    {
        $clone = clone $this;
        $clone->limit(1);
        $rows = $clone->get();
        return $rows[0] ?? null;
    }

    /** @param array{0: mixed, 1: mixed} $range */
    public function whereBetween(string $column, array $range): self
    {
        $min = $range[0] ?? 0;
        $max = $range[1] ?? 0;
        $paramMin = 'wb_min_' . $this->whereCounter;
        $paramMax = 'wb_max_' . $this->whereCounter;
        $this->whereCounter++;

        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => 'AND',
            'sql' => $this->compiler->quoteIdentifier($column) . ' BETWEEN :' . $paramMin . ' AND :' . $paramMax,
        ];
        $this->bindings[$paramMin] = $min;
        $this->bindings[$paramMax] = $max;
        return $this;
    }

    /** @param array{0: mixed, 1: mixed} $range */
    public function whereNotBetween(string $column, array $range): self
    {
        $min = $range[0] ?? 0;
        $max = $range[1] ?? 0;
        $paramMin = 'wbn_min_' . $this->whereCounter;
        $paramMax = 'wbn_max_' . $this->whereCounter;
        $this->whereCounter++;

        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => 'AND',
            'sql' => $this->compiler->quoteIdentifier($column) . ' NOT BETWEEN :' . $paramMin . ' AND :' . $paramMax,
        ];
        $this->bindings[$paramMin] = $min;
        $this->bindings[$paramMax] = $max;
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => 'AND',
            'sql' => $this->compiler->quoteIdentifier($column) . ' IS NULL',
        ];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => 'AND',
            'sql' => $this->compiler->quoteIdentifier($column) . ' IS NOT NULL',
        ];
        return $this;
    }

    public function orWhereNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => 'OR',
            'sql' => $this->compiler->quoteIdentifier($column) . ' IS NULL',
        ];
        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => 'OR',
            'sql' => $this->compiler->quoteIdentifier($column) . ' IS NOT NULL',
        ];
        return $this;
    }

    /** @return array<int|string, mixed> */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $this->select([$column, $key ?? $column])->get();
        $result = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $value = $row[$column] ?? null;
            if ($key !== null && isset($row[$key])) {
                /** @var string|int $rowKey */
                $rowKey = $row[$key];
                $result[strval($rowKey)] = $value;
            } else {
                $result[] = $value;
            }
        }
        return $result;
    }

    public function value(string $column): mixed
    {
        $row = $this->select([$column])->first();
        return $row[$column] ?? null;
    }

    public function chunk(int $size, callable $callback): bool
    {
        $page = 1;
        $offset = 0;

        do {
            $clone = clone $this;
            $rows = $clone->limit($size)->offset($offset)->get();

            if ($rows === []) {
                return true;
            }

            $callback($rows);
            $page++;
            $offset = ($page - 1) * $size;
        } while (count($rows) === $size);

        return true;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return $this->count() === 0;
    }

    public function setPrimaryKey(string $key): self
    {
        $this->primaryKey = $key;
        return $this;
    }

    public function inRandomOrder(?int $seed = null): self
    {
        $driver = 'mysql';
        try {
            $driver = Database::connection($this->connectionName)->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
        }

        $sql = match ($driver) {
            'pgsql', 'postgres', 'postgresql' => 'RANDOM()',
            'sqlite' => 'RANDOM()',
            default => $seed !== null ? 'RAND(' . (int) $seed . ')' : 'RAND()',
        };

        $this->orders[] = ['column' => $sql, 'direction' => 'ASC', 'raw' => true];
        return $this;
    }

    public function dump(): self
    {
        [$sql, $bindings] = $this->compiler->buildSelectQuery(
            $this->columns, $this->table, $this->wheres, $this->havings,
            $this->joins, $this->groups, $this->orders,
            $this->limitValue, $this->offsetValue, $this->bindings
        );
        echo PHP_EOL . 'SQL: ' . $sql . PHP_EOL;
        echo 'Bindings: ' . json_encode($bindings, JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL;
        return $this;
    }

    public function dd(): never
    {
        $this->dump();
        exit(1);
    }

    public function toSql(): string
    {
        [$sql] = $this->compiler->buildSelectQuery(
            $this->columns, $this->table, $this->wheres, $this->havings,
            $this->joins, $this->groups, $this->orders,
            $this->limitValue, $this->offsetValue, $this->bindings
        );
        return $sql;
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function sum(string $column): float|int
    {
        return $this->aggregate('SUM', $column);
    }

    public function avg(string $column): float|int
    {
        return $this->aggregate('AVG', $column);
    }

    public function max(string $column): float|int
    {
        return $this->aggregate('MAX', $column);
    }

    public function min(string $column): float|int
    {
        return $this->aggregate('MIN', $column);
    }

    private function aggregate(string $function, string $column): float|int
    {
        [$sql, $bindings] = $this->compiler->buildAggregateQuery(
            $function, $column, $this->table, $this->wheres, $this->havings,
            $this->joins, $this->groups, $this->bindings
        );
        $rows = $this->runSelect($sql, $bindings);
        $value = $rows[0]['aggregate'] ?? 0;

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }
        return 0;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        if ($data === []) {
            return 0;
        }

        [$sql, $bindings, $returning] = $this->compiler->buildInsertSql($this->table, $data, $this->primaryKey);

        $stmt = Database::connection($this->connectionName)->prepare($sql);
        $stmt->execute($bindings);
        Cache::flushQueryBuilderTable($this->cacheTable);

        if ($returning !== '') {
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false && isset($row['id'])) {
                /** @var int|string $id */
                $id = $row['id'];
                return intval($id);
            }
            return $stmt->rowCount();
        }

        $lastId = Database::connection($this->connectionName)->lastInsertId();
        return $lastId !== false && $lastId !== '0' ? (int) $lastId : $stmt->rowCount();
    }

    /** @param array<string, mixed> $data */
    public function insertGetId(array $data): int
    {
        return $this->insert($data);
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): int
    {
        if ($data === []) {
            return 0;
        }

        [$sql, $allBindings] = $this->compiler->buildUpdateSql($this->table, $data, $this->wheres, $this->bindings);

        $stmt = Database::connection($this->connectionName)->prepare($sql);
        $stmt->execute($allBindings);
        Cache::flushQueryBuilderTable($this->cacheTable);

        return $stmt->rowCount();
    }

    public function delete(): int
    {
        [$sql, $bindings] = $this->compiler->buildDeleteSql($this->table, $this->wheres, $this->bindings);

        $stmt = Database::connection($this->connectionName)->prepare($sql);
        $stmt->execute($bindings);
        Cache::flushQueryBuilderTable($this->cacheTable);

        return $stmt->rowCount();
    }

    /**
     * Bulk update where ids in array.
     *
     * @param array<int, int|string> $ids
     * @param array<string, mixed> $data
     * @return int Number of affected rows
     */
    public function updateWhereIn(array $ids, array $data): int
    {
        if ($ids === [] || $data === []) {
            return 0;
        }

        $sets = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $name = 'u_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $column);
            $sets[] = sprintf('%s = :%s', $this->compiler->quoteIdentifier($column), $name);
            $bindings[$name] = $value;
        }

        $placeholders = [];
        $idBindings = [];
        foreach ($ids as $i => $id) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $idBindings[$key] = $id;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE id IN (%s)',
            $this->compiler->quoteIdentifier($this->table),
            implode(', ', $sets),
            implode(', ', $placeholders)
        );

        $stmt = Database::connection($this->connectionName)->prepare($sql);
        $stmt->execute([...$bindings, ...$idBindings]);
        Cache::flushQueryBuilderTable($this->cacheTable);

        return $stmt->rowCount();
    }

    /**
     * Bulk delete where ids in array.
     *
     * @param array<int, int|string> $ids
     * @return int Number of deleted rows
     */
    public function deleteWhereIn(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = [];
        $bindings = [];
        foreach ($ids as $i => $id) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE id IN (%s)',
            $this->compiler->quoteIdentifier($this->table),
            implode(', ', $placeholders)
        );

        $stmt = Database::connection($this->connectionName)->prepare($sql);
        $stmt->execute($bindings);
        Cache::flushQueryBuilderTable($this->cacheTable);

        return $stmt->rowCount();
    }

    /**
     * Bulk insert multiple rows with chunking to prevent max_allowed_packet issues.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int Number of inserted rows
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $totalInserted = 0;
        $chunks = array_chunk($rows, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            [$sql, $allBindings] = $this->compiler->buildInsertManySql($this->table, $chunk);

            $stmt = Database::connection($this->connectionName)->prepare($sql);
            $stmt->execute($allBindings);
            $totalInserted += $stmt->rowCount();
        }

        Cache::flushQueryBuilderTable($this->cacheTable);
        return $totalInserted;
    }

    /**
     * Cursor-based pagination for large datasets.
     *
     * @param int $perPage Items per page
     * @param array{id?: int|string, created_at?: string}|null $cursor
     * @param string $order 'asc' or 'desc'
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, next_cursor: array<string, mixed>|null}
     */
    public function cursorPaginate(int $perPage = 15, ?array $cursor = null, string $order = 'asc'): array
    {
        $perPage = max(1, $perPage);
        $order = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

        $total = $this->count();

        $clone = clone $this;

        if ($cursor !== null && isset($cursor['id'], $cursor['created_at'])) {
            $cursorId = (int) $cursor['id'];
            $cursorCreatedAt = (string) $cursor['created_at'];

            if ($order === 'ASC') {
                $clone = $clone->whereRaw(
                    "(created_at > :cursor_created_at_1 OR (created_at = :cursor_created_at_2 AND id > :cursor_id_3))",
                    ['cursor_created_at_1' => $cursorCreatedAt, 'cursor_created_at_2' => $cursorCreatedAt, 'cursor_id_3' => $cursorId]
                );
            } else {
                $clone = $clone->whereRaw(
                    "(created_at < :cursor_created_at_1 OR (created_at = :cursor_created_at_2 AND id < :cursor_id_3))",
                    ['cursor_created_at_1' => $cursorCreatedAt, 'cursor_created_at_2' => $cursorCreatedAt, 'cursor_id_3' => $cursorId]
                );
            }
        }

        $countRows = $clone->limit($perPage + 1)->get();
        $hasMore = count($countRows) > $perPage;

        if ($hasMore) {
            array_pop($countRows);
        }

        $lastRow = $countRows[count($countRows) - 1] ?? null;
        $nextCursor = null;
        if ($hasMore && $lastRow !== null) {
            /** @var array<string, mixed> $lastRow */
            /** @var int|string $idVal */
            $idVal = $lastRow['id'] ?? 0;
            /** @var string|int|float|null $createdAtVal */
            $createdAtVal = $lastRow['created_at'] ?? date('Y-m-d H:i:s');
            $nextCursor = [
                'id' => intval($idVal),
                'created_at' => strval($createdAtVal),
            ];
        }

        $rows = $countRows;

        return [
            'data' => $rows,
            'meta' => [
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $hasMore,
                'order' => $order,
                'cursor' => $cursor,
            ],
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return array<string, mixed> */
    public function paginate(int $perPage, ?int $page = null): array
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page ?? 1);
        $offset = ($page - 1) * $perPage;

        [$countSql, $countBindings] = $this->compiler->buildCountQuery(
            $this->table, $this->wheres, $this->havings,
            $this->joins, $this->groups, $this->bindings
        );
        $countRows = $this->runSelect($countSql, $countBindings);
        /** @var int|string $aggregate */
        $aggregate = $countRows[0]['aggregate'] ?? 0;
        $total = intval($aggregate);

        $clone = clone $this;
        $rows = $clone->limit($perPage)->offset($offset)->get();

        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    private function addWhere(string $boolean, string $column, mixed $operatorOrValue, mixed $value, bool $hasExplicitValue): self
    {
        [$operator, $resolvedValue] = $this->resolveOperatorAndValue($operatorOrValue, $value, $hasExplicitValue);
        $param = 'w_' . $this->whereCounter;
        $this->whereCounter++;

        $this->wheres[] = [
            'type' => 'basic',
            'boolean' => $boolean,
            'column' => trim($column),
            'operator' => $operator,
            'param' => $param,
        ];
        $this->bindings[$param] = $resolvedValue;

        return $this;
    }

    /** @param array<int|string, mixed> $values */
    private function addWhereIn(string $boolean, string $column, array $values, bool $not): self
    {
        if ($values === []) {
            $this->wheres[] = [
                'type' => 'raw',
                'boolean' => $boolean,
                'sql' => $not ? '1 = 1' : '1 = 0',
            ];
            return $this;
        }

        $prefix = 'wi_' . $this->inCounter;
        $this->inCounter++;
        $params = [];

        foreach (array_values($values) as $idx => $value) {
            $param = $prefix . '_' . $idx;
            $params[] = $param;
            $this->bindings[$param] = $value;
        }

        $this->wheres[] = [
            'type' => 'in',
            'boolean' => $boolean,
            'column' => trim($column),
            'not' => $not,
            'params' => $params,
        ];

        return $this;
    }

    private function addHaving(string $boolean, string $column, mixed $operatorOrValue, mixed $value, bool $hasExplicitValue): self
    {
        [$operator, $resolvedValue] = $this->resolveOperatorAndValue($operatorOrValue, $value, $hasExplicitValue);
        $param = 'h_' . $this->havingCounter;
        $this->havingCounter++;

        $this->havings[] = [
            'boolean' => $boolean,
            'column' => trim($column),
            'operator' => $operator,
            'param' => $param,
        ];
        $this->bindings[$param] = $resolvedValue;

        return $this;
    }

    /** @return array{0:string,1:mixed} */
    private function resolveOperatorAndValue(mixed $operatorOrValue, mixed $value, bool $hasExplicitValue): array
    {
        if (!$hasExplicitValue) {
            return ['=', $operatorOrValue];
        }
        /** @var string $operatorOrValue */
        $operator = $this->compiler->normalizeOperator($operatorOrValue);
        return [$operator, $value];
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    private function runSelect(string $sql, array $bindings): array
    {
        $cachePrefix = 'qb:' . $this->cacheTable . ':';
        return Database::selectCached($sql, $bindings, $this->cacheTtl, $cachePrefix);
    }
}
