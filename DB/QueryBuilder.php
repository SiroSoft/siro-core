<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use RuntimeException;
use Siro\Core\Cache;
use Siro\Core\Database;

final class QueryBuilder
{
    private string $table = '';
    /** @var array<int, string> */
    private array $columns = ['*'];
    /** @var array<int, array<string, mixed>> */
    private array $wheres = [];
    /** @var array<int, array<string, mixed>> */
    private array $havings = [];
    /** @var array<int, array{type:string,table:string,first:string,operator:string,second:string}> */
    private array $joins = [];
    /** @var array<int, string> */
    private array $groups = [];
    /** @var array<int, array{column:string,direction:string}> */
    private array $orders = [];
    /** @var array<string, mixed> */
    private array $bindings = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private int $whereCounter = 0;
    private int $havingCounter = 0;
    private int $inCounter = 0;
    private int $cacheTtl = 0;
    private string $cacheTable = '';

    public function __construct(string $table)
    {
        $this->table($table);
    }

    public function table(string $table): self
    {
        $this->table = trim($table);
        if ($this->table === '') {
            throw new RuntimeException('QueryBuilder table name cannot be empty.');
        }

        $this->cacheTable = $this->detectTableName($this->table);

        return $this;
    }

    public function select(array|string $columns): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        $normalized = [];
        foreach ($columns as $column) {
            $column = trim((string) $column);
            if ($column !== '') {
                $normalized[] = $column;
            }
        }

        $this->columns = $normalized === [] ? ['*'] : $normalized;
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

    public function whereIn(string $column, array $values): self
    {
        return $this->addWhereIn('AND', $column, $values, false);
    }

    public function orWhereIn(string $column, array $values): self
    {
        return $this->addWhereIn('OR', $column, $values, false);
    }

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
            'operator' => $this->normalizeOperator($operator),
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
            'operator' => $this->normalizeOperator($operator),
            'second' => trim($second),
        ];

        return $this;
    }

    public function groupBy(array|string $columns): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        foreach ($columns as $column) {
            $column = trim((string) $column);
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

    /** @return array<int, array<string, mixed>> */
    public function get(): array
    {
        [$sql, $bindings] = $this->buildSelectQuery();
        return $this->runSelect($sql, $bindings);
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limit(1);
        $rows = $clone->get();
        return $rows[0] ?? null;
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

    public function insert(array $data): int|string
    {
        if ($data === []) {
            return 0;
        }

        $columns = [];
        $holders = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $name = 'i_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $column);
            $columns[] = (string) $column;
            $holders[] = ':' . $name;
            $bindings[$name] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $holders)
        );

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($bindings);
        Cache::flushQueryBuilderTable($this->cacheTable);

        $lastId = Database::connection()->lastInsertId();
        return $lastId !== '0' ? $lastId : $stmt->rowCount();
    }

    public function update(array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $sets = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $name = 'u_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $column);
            $sets[] = sprintf('%s = :%s', $column, $name);
            $bindings[$name] = $value;
        }

        [$whereSql, $whereBindings] = $this->compileWhere();
        $sql = sprintf('UPDATE %s SET %s%s', $this->table, implode(', ', $sets), $whereSql);

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([...$bindings, ...$whereBindings]);
        Cache::flushQueryBuilderTable($this->cacheTable);

        return $stmt->rowCount();
    }

    public function delete(): int
    {
        [$whereSql, $whereBindings] = $this->compileWhere();
        $sql = sprintf('DELETE FROM %s%s', $this->table, $whereSql);

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($whereBindings);
        Cache::flushQueryBuilderTable($this->cacheTable);

        return $stmt->rowCount();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(int $perPage): array
    {
        $perPage = max(1, $perPage);
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$countSql, $countBindings] = $this->buildCountQuery();
        $countRows = $this->runSelect($countSql, $countBindings);
        $total = (int) (($countRows[0]['aggregate'] ?? 0));

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

    private function aggregate(string $function, string $column): float|int
    {
        [$sql, $bindings] = $this->buildAggregateQuery($function, $column);
        $rows = $this->runSelect($sql, $bindings);
        $value = $rows[0]['aggregate'] ?? 0;

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return 0;
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

        $operator = $this->normalizeOperator((string) $operatorOrValue);
        return [$operator, $value];
    }

    private function normalizeOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'];

        if (!in_array($operator, $allowed, true)) {
            throw new RuntimeException('Unsupported SQL operator: ' . $operator);
        }

        return $operator;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildSelectQuery(): array
    {
        [$whereSql, $whereBindings] = $this->compileWhere();
        [$havingSql, $havingBindings] = $this->compileHaving();
        $columns = implode(', ', $this->columns);

        $sql = sprintf('SELECT %s FROM %s', $columns, $this->table);
        $sql .= $this->compileJoins();
        $sql .= $whereSql;
        $sql .= $this->compileGroupBy();
        $sql .= $havingSql;
        $sql .= $this->compileOrderBy();

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }

        if ($this->offsetValue !== null) {
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return [$sql, [...$whereBindings, ...$havingBindings]];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildCountQuery(): array
    {
        [$whereSql, $whereBindings] = $this->compileWhere();
        [$havingSql, $havingBindings] = $this->compileHaving();

        if ($this->groups === []) {
            $sql = sprintf('SELECT COUNT(*) AS aggregate FROM %s', $this->table);
            $sql .= $this->compileJoins() . $whereSql . $havingSql;
            return [$sql, [...$whereBindings, ...$havingBindings]];
        }

        $subQuery = sprintf('SELECT 1 FROM %s', $this->table)
            . $this->compileJoins()
            . $whereSql
            . $this->compileGroupBy()
            . $havingSql;

        return ['SELECT COUNT(*) AS aggregate FROM (' . $subQuery . ') AS siro_count_table', [...$whereBindings, ...$havingBindings]];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildAggregateQuery(string $function, string $column): array
    {
        [$whereSql, $whereBindings] = $this->compileWhere();
        [$havingSql, $havingBindings] = $this->compileHaving();

        if ($this->groups === []) {
            $sql = sprintf('SELECT %s(%s) AS aggregate FROM %s', strtoupper($function), $column, $this->table);
            $sql .= $this->compileJoins() . $whereSql . $havingSql;
            return [$sql, [...$whereBindings, ...$havingBindings]];
        }

        $subQuery = sprintf('SELECT %s(%s) AS aggregate FROM %s', strtoupper($function), $column, $this->table)
            . $this->compileJoins()
            . $whereSql
            . $this->compileGroupBy()
            . $havingSql;

        return ['SELECT ' . strtoupper($function) . '(aggregate) AS aggregate FROM (' . $subQuery . ') AS siro_aggregate_table', [...$whereBindings, ...$havingBindings]];
    }

    private function compileJoins(): string
    {
        if ($this->joins === []) {
            return '';
        }

        $parts = [];
        foreach ($this->joins as $join) {
            $parts[] = sprintf(
                ' %s JOIN %s ON %s %s %s',
                $join['type'],
                $join['table'],
                $join['first'],
                $join['operator'],
                $join['second']
            );
        }

        return implode('', $parts);
    }

    private function compileGroupBy(): string
    {
        if ($this->groups === []) {
            return '';
        }

        return ' GROUP BY ' . implode(', ', $this->groups);
    }

    private function compileOrderBy(): string
    {
        if ($this->orders === []) {
            return '';
        }

        $parts = [];
        foreach ($this->orders as $order) {
            $parts[] = $order['column'] . ' ' . $order['direction'];
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function compileWhere(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }

        $parts = [];
        $bindings = [];

        foreach ($this->wheres as $index => $where) {
            $prefix = $index === 0 ? '' : ' ' . $where['boolean'] . ' ';

            if (($where['type'] ?? 'basic') === 'raw') {
                $parts[] = $prefix . $where['sql'];
                continue;
            }

            if (($where['type'] ?? 'basic') === 'in') {
                $holderParts = [];
                foreach ($where['params'] as $param) {
                    $holderParts[] = ':' . $param;
                    $bindings[$param] = $this->bindings[$param];
                }

                $parts[] = $prefix . $where['column'] . ($where['not'] ? ' NOT IN (' : ' IN (') . implode(', ', $holderParts) . ')';
                continue;
            }

            $parts[] = $prefix . $where['column'] . ' ' . $where['operator'] . ' :' . $where['param'];
            $bindings[$where['param']] = $this->bindings[$where['param']];
        }

        return [' WHERE ' . implode('', $parts), $bindings];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function compileHaving(): array
    {
        if ($this->havings === []) {
            return ['', []];
        }

        $parts = [];
        $bindings = [];

        foreach ($this->havings as $index => $having) {
            $prefix = $index === 0 ? '' : ' ' . $having['boolean'] . ' ';
            $parts[] = $prefix . $having['column'] . ' ' . $having['operator'] . ' :' . $having['param'];
            $bindings[$having['param']] = $this->bindings[$having['param']];
        }

        return [' HAVING ' . implode('', $parts), $bindings];
    }

    /** @param array<string,mixed> $bindings
     *  @return array<int, array<string,mixed>>
     */
    private function runSelect(string $sql, array $bindings): array
    {
        $cachePrefix = 'qb:' . $this->cacheTable . ':';
        return Database::selectCached($sql, $bindings, $this->cacheTtl, $cachePrefix);
    }

    private function detectTableName(string $table): string
    {
        $normalized = strtolower(trim($table));
        if ($normalized === '') {
            return 'default';
        }

        $parts = preg_split('/\s+/', $normalized) ?: [];
        $first = (string) ($parts[0] ?? '');
        $first = trim($first, "`\" ");

        if ($first === '') {
            return 'default';
        }

        if (str_contains($first, '.')) {
            $segments = explode('.', $first);
            $first = (string) end($segments);
        }

        return $first !== '' ? $first : 'default';
    }
}
