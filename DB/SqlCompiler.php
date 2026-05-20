<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use RuntimeException;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\DB\RawExpression;

final class SqlCompiler
{
    private ?string $connectionName = null;

    public function setConnection(?string $name): void
    {
        $this->connectionName = $name;
    }

    public function setTable(string $table): void
    {
    }

    /** @var array<string, string> */
    private static array $driverNames = [];

    public static function resetDriverNames(): void
    {
        self::$driverNames = [];
    }

    public function detectDriver(?string $connectionName = null): string
    {
        $key = $connectionName ?? $this->connectionName ?? 'default';
        if (!isset(self::$driverNames[$key])) {
            try {
                $driver = Database::connection($connectionName ?? $this->connectionName)->getAttribute(\PDO::ATTR_DRIVER_NAME);
                self::$driverNames[$key] = is_string($driver) ? $driver : 'mysql';
            } catch (\Throwable) {
                self::$driverNames[$key] = 'mysql';
            }
        }
        return self::$driverNames[$key];
    }

    /** @var array<string, string> */
    private static array $quotedIdentifierCache = [];

    public function quoteIdentifier(string $identifier): string
    {
        if (isset(self::$quotedIdentifierCache[$identifier])) {
            return self::$quotedIdentifierCache[$identifier];
        }

        if ($identifier === '*') {
            self::$quotedIdentifierCache[$identifier] = $identifier;
            return $identifier;
        }

        if (preg_match('/[^a-zA-Z0-9_.\s\-]/', $identifier)) {
            throw new \RuntimeException('Identifier contains illegal characters (semicolons, comments, parentheses). Use RawExpression or raw methods for SQL functions.');
        }

        if (stripos($identifier, ';') !== false ||
            stripos($identifier, '--') !== false ||
            stripos($identifier, '/*') !== false) {
            throw new \RuntimeException('Invalid identifier: SQL injection attempt detected');
        }

        if (str_contains($identifier, '(') || str_contains($identifier, ')')) {
            throw new \RuntimeException('Invalid identifier: function calls and parentheses not allowed');
        }

        $driver = $this->detectDriver();
        $char = match ($driver) {
            'pgsql', 'postgres', 'postgresql' => '"',
            default => '`',
        };
        $escaped = str_replace($char, $char . $char, $identifier);

        $parts = explode('.', $escaped);
        foreach ($parts as $i => $part) {
            $part = trim($part);
            if ($part !== '*' && $part !== '') {
                $parts[$i] = $char . $part . $char;
            }
        }

        $result = implode('.', $parts);
        self::$quotedIdentifierCache[$identifier] = $result;
        return $result;
    }

    public function quoteColumnList(string $columns): string
    {
        $parts = explode(',', $columns);
        foreach ($parts as $i => $part) {
            $parts[$i] = $this->quoteIdentifier(trim($part));
        }
        return implode(', ', $parts);
    }

    private function isRawColumn(string $column): bool
    {
        if ($column === '*') {
            return false;
        }
        if (str_contains($column, '(') || str_contains($column, ')')) {
            return true;
        }
        if (preg_match('/\b(AS|DISTINCT|CASE|WHEN|THEN|ELSE|END)\b/i', $column)) {
            return true;
        }
        return false;
    }

    /**
     * @param array<int, string|RawExpression> $columns
     * @param array<int, array{type:'basic', boolean:string, column:string, operator:string, param:string}|array{type:'raw', boolean:string, sql:string, bindings?:mixed}|array{type:'in', boolean:string, column:string, not:bool, params:array<int, string>}> $wheres
     * @param array<int, array{boolean:string, column:string, operator:string, param:string}> $havings
 * @param array<int, array{type:string, table:string, first?:string, operator?:string, second?:string, clause?:JoinClause}> $joins
 * @param array<int, string|RawExpression> $groups
 * @param array<int, array{column:string, direction:string}> $orders
 * @param array<string, mixed> $bindings
 * @return array{0: string, 1: array<int|string, mixed>}
 */
public function buildSelectQuery(
        array $columns,
        string $table,
        array $wheres,
        array $havings,
        array $joins,
        array $groups,
        array $orders,
        ?int $limitValue,
        ?int $offsetValue,
        array $bindings,
        string $lockMode = '',
    ): array {
        [$whereSql, $whereBindings] = $this->compileWhere($wheres, $bindings);
        [$havingSql, $havingBindings] = $this->compileHaving($havings, $bindings);

        $quotedColumns = [];
        foreach ($columns as $column) {
            if ($column instanceof RawExpression) {
                $quotedColumns[] = $column->getValue();
                continue;
            }
            $column = trim($column);
            if ($column === '') {
                continue;
            }
            if ($this->isRawColumn($column)) {
                $quotedColumns[] = $column;
            } else {
                $quotedColumns[] = $this->quoteIdentifier($column);
            }
        }
        $sql = sprintf('SELECT %s FROM %s', implode(', ', $quotedColumns), $this->quoteIdentifier($table));
        $sql .= $this->compileJoins($joins);
        $sql .= $whereSql;
        $sql .= $this->compileGroupBy($groups);
        $sql .= $havingSql;
        $sql .= $this->compileOrderBy($orders);

        if ($limitValue !== null) {
            $sql .= ' LIMIT ' . $limitValue;
        }
        if ($offsetValue !== null) {
            $sql .= ' OFFSET ' . $offsetValue;
        }

        if ($lockMode !== '') {
            $sql .= ' ' . $lockMode;
        }

        return [$sql, [...$whereBindings, ...$havingBindings]];
    }

    /**
     * @param array<int, array{type:'basic', boolean:string, column:string, operator:string, param:string}|array{type:'raw', boolean:string, sql:string, bindings?:mixed}|array{type:'in', boolean:string, column:string, not:bool, params:array<int, string>}> $wheres
     * @param array<int, array{boolean:string, column:string, operator:string, param:string}> $havings
 * @param array<int, array{type:string, table:string, first?:string, operator?:string, second?:string, clause?:JoinClause}> $joins
 * @param array<int, string|RawExpression> $groups
 * @param array<string, mixed> $bindings
 * @return array{0: string, 1: array<int|string, mixed>}
 */
public function buildCountQuery(
        string $table,
        array $wheres,
        array $havings,
        array $joins,
        array $groups,
        array $bindings,
    ): array {
        [$whereSql, $whereBindings] = $this->compileWhere($wheres, $bindings);
        [$havingSql, $havingBindings] = $this->compileHaving($havings, $bindings);

        if ($groups === []) {
            $sql = sprintf('SELECT COUNT(*) AS aggregate FROM %s', $this->quoteIdentifier($table));
            $sql .= $this->compileJoins($joins) . $whereSql . $havingSql;
            return [$sql, [...$whereBindings, ...$havingBindings]];
        }

        $subQuery = sprintf('SELECT 1 FROM %s', $this->quoteIdentifier($table))
            . $this->compileJoins($joins)
            . $whereSql
            . $this->compileGroupBy($groups)
            . $havingSql;

        return ['SELECT COUNT(*) AS aggregate FROM (' . $subQuery . ') AS siro_count_table', [...$whereBindings, ...$havingBindings]];
    }

    /**
     * @param array<int, array{type:'basic', boolean:string, column:string, operator:string, param:string}|array{type:'raw', boolean:string, sql:string, bindings?:mixed}|array{type:'in', boolean:string, column:string, not:bool, params:array<int, string>}> $wheres
     * @param array<int, array{boolean:string, column:string, operator:string, param:string}> $havings
 * @param array<int, array{type:string, table:string, first?:string, operator?:string, second?:string, clause?:JoinClause}> $joins
 * @param array<int, string|RawExpression> $groups
 * @param array<string, mixed> $bindings
 * @return array{0: string, 1: array<int|string, mixed>}
 */
public function buildAggregateQuery(
        string $function,
        string $column,
        string $table,
        array $wheres,
        array $havings,
        array $joins,
        array $groups,
        array $bindings,
    ): array {
        [$whereSql, $whereBindings] = $this->compileWhere($wheres, $bindings);
        [$havingSql, $havingBindings] = $this->compileHaving($havings, $bindings);

        if ($groups === []) {
            $sql = sprintf('SELECT %s(%s) AS aggregate FROM %s', strtoupper($function), $this->quoteIdentifier($column), $this->quoteIdentifier($table));
            $sql .= $this->compileJoins($joins) . $whereSql . $havingSql;
            return [$sql, [...$whereBindings, ...$havingBindings]];
        }

        $subQuery = sprintf('SELECT %s(%s) AS aggregate FROM %s', strtoupper($function), $this->quoteIdentifier($column), $this->quoteIdentifier($table))
            . $this->compileJoins($joins)
            . $whereSql
            . $this->compileGroupBy($groups)
            . $havingSql;

        return ['SELECT ' . strtoupper($function) . '(aggregate) AS aggregate FROM (' . $subQuery . ') AS siro_aggregate_table', [...$whereBindings, ...$havingBindings]];
    }

    /**
     * @param array<int, array{type:string, table:string, first?:string, operator?:string, second?:string, clause?:JoinClause}> $joins
     */
    public function compileJoins(array $joins): string
    {
        if ($joins === []) {
            return '';
        }

        $parts = [];
        foreach ($joins as $join) {
            if (isset($join['clause'])) {
                /** @var JoinClause $clause */
                $clause = $join['clause'];
                $tableName = $join['table'] !== '' ? $join['table'] : $clause->table;
                $table = $this->quoteIdentifier($tableName);
                $conditions = $clause->compile();
                $onClause = $conditions !== '' ? 'ON ' . $conditions : '';
                $parts[] = sprintf(' %s JOIN %s %s', $join['type'], $table, $onClause);
            } else {
                $parts[] = sprintf(
                    ' %s JOIN %s ON %s %s %s',
                    $join['type'],
                    $this->quoteIdentifier($join['table']),
                    $this->quoteIdentifier($join['first'] ?? ''),
                    $join['operator'] ?? '=',
                    $this->quoteIdentifier($join['second'] ?? '')
                );
            }
        }

        return implode('', $parts);
    }

    /**
     * @param array<int, string|RawExpression> $groups
     */
    public function compileGroupBy(array $groups): string
    {
        if ($groups === []) {
            return '';
        }

        $quoted = [];
        foreach ($groups as $col) {
            if ($col instanceof RawExpression) {
                $quoted[] = $col->getValue();
            } elseif (str_contains($col, '(')) {
                $quoted[] = $col;
            } else {
                $quoted[] = $this->quoteIdentifier($col);
            }
        }
        return ' GROUP BY ' . implode(', ', $quoted);
    }

    /**
     * @param array<int, array{column:string, direction:string}> $orders
     */
    /**
     * @param array<int, array{column:string, direction:string, raw?:bool}> $orders
     */
    public function compileOrderBy(array $orders): string
    {
        if ($orders === []) {
            return '';
        }

        $parts = [];
        foreach ($orders as $order) {
            $isRaw = isset($order['raw']) && $order['raw'];
            $column = $isRaw ? $order['column'] : $this->quoteIdentifier($order['column']);
            $parts[] = $column . ' ' . $order['direction'];
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * @param array<int, array{type:'basic', boolean:string, column:string, operator:string, param:string}|array{type:'raw', boolean:string, sql:string, bindings?:mixed}|array{type:'in', boolean:string, column:string, not:bool, params:array<int, string>}> $wheres
     * @param array<string, mixed> $bindings
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    public function compileWhere(array $wheres, array $bindings): array
    {
        if ($wheres === []) {
            return ['', []];
        }

        $parts = [];
        $resultBindings = [];

        foreach ($wheres as $index => $where) {
            $prefix = $index === 0 ? '' : ' ' . $where['boolean'] . ' ';

            if ($where['type'] === 'raw') {
                $parts[] = $prefix . $where['sql'];
                if (isset($where['bindings']) && is_array($where['bindings'])) {
                    foreach ($where['bindings'] as $bk => $bv) {
                        if (is_string($bk)) {
                            $resultBindings[$bk] = $bv;
                        } else {
                            $resultBindings[] = $bv;
                        }
                    }
                }
                continue;
            }

            if ($where['type'] === 'in') {
                $holderParts = [];
                foreach ($where['params'] as $param) {
                    $holderParts[] = ':' . $param;
                    $resultBindings[$param] = $bindings[$param];
                }
                $parts[] = $prefix . $this->quoteIdentifier($where['column']) . ($where['not'] ? ' NOT IN (' : ' IN (') . implode(', ', $holderParts) . ')';
                continue;
            }

            $parts[] = $prefix . $this->quoteIdentifier($where['column']) . ' ' . $where['operator'] . ' :' . $where['param'];
            $resultBindings[$where['param']] = $bindings[$where['param']];
        }

        return [' WHERE ' . implode('', $parts), $resultBindings];
    }

    /**
     * @param array<int, array{boolean:string, column:string, operator:string, param:string, raw?:bool, sql?:string, bindings?:array<int|string, mixed>}> $havings
     * @param array<string, mixed> $bindings
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function compileHaving(array $havings, array $bindings): array
    {
        if ($havings === []) {
            return ['', []];
        }

        $parts = [];
        /** @var array<string, mixed> $resultBindings */
        $resultBindings = [];

        foreach ($havings as $index => $having) {
            $prefix = $index === 0 ? '' : ' ' . $having['boolean'] . ' ';

            if (isset($having['raw']) && $having['raw']) {
                $parts[] = $prefix . ($having['sql'] ?? '');
                if (isset($having['bindings'])) {
                    foreach ($having['bindings'] as $bk => $bv) {
                        $resultBindings[(string) $bk] = $bv;
                    }
                }
                continue;
            }

            $parts[] = $prefix . $this->quoteIdentifier($having['column']) . ' ' . $having['operator'] . ' :' . $having['param'];
            $resultBindings[$having['param']] = $bindings[$having['param']];
        }

        return [' HAVING ' . implode('', $parts), $resultBindings];
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function runSelect(string $sql, array $bindings, int $cacheTtl, string $cachePrefix): array
    {
        return Database::selectCached($sql, $bindings, $cacheTtl, $cachePrefix);
    }

    public function detectTableName(string $table): string
    {
        $normalized = strtolower(trim($table));
        if ($normalized === '') {
            return 'default';
        }

        $parts = preg_split('/\s+/', $normalized);
        $first = (string) ($parts !== false ? $parts[0] : '');
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

    /**
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    public function buildInsertSql(string $table, array $data, string $primaryKey): array
    {
        $columns = [];
        $holders = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $name = 'i_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $column);
            $columns[] = (string) $column;
            $holders[] = ':' . $name;
            $bindings[$name] = $value;
        }

        $quotedColumns = array_map(fn (string $col): string => $this->quoteIdentifier($col), $columns);
        $driver = $this->detectDriver();
        $returning = in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) ? ' RETURNING ' . $this->quoteIdentifier($primaryKey) : '';

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)%s',
            $this->quoteIdentifier($table),
            implode(', ', $quotedColumns),
            implode(', ', $holders),
            $returning
        );

        return [$sql, $bindings, $returning];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function buildInsertManySql(string $table, array $rows): array
    {
        $columns = array_keys($rows[0]);
        $quotedColumns = array_map(fn (string $col): string => $this->quoteIdentifier($col), $columns);

        $allPlaceholders = [];
        $allBindings = [];
        foreach ($rows as $rowIndex => $row) {
            $rowPlaceholders = [];
            foreach ($columns as $colIndex => $col) {
                $key = 'r' . $rowIndex . '_c' . $colIndex;
                $rowPlaceholders[] = ':' . $key;
                $allBindings[$key] = $row[$col] ?? null;
            }
            $allPlaceholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->quoteIdentifier($table),
            implode(', ', $quotedColumns),
            implode(', ', $allPlaceholders)
        );

        return [$sql, $allBindings];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{type:'basic', boolean:string, column:string, operator:string, param:string}|array{type:'raw', boolean:string, sql:string, bindings?:mixed}|array{type:'in', boolean:string, column:string, not:bool, params:array<int, string>}> $wheres
     * @param array<string, mixed> $bindings
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    public function buildUpdateSql(string $table, array $data, array $wheres, array $bindings): array
    {
        $sets = [];
        $setBindings = [];

        foreach ($data as $column => $value) {
            $name = 'u_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $column);
            $sets[] = sprintf('%s = :%s', $this->quoteIdentifier($column), $name);
            $setBindings[$name] = $value;
        }

        [$whereSql, $whereBindings] = $this->compileWhere($wheres, $bindings);
        $sql = sprintf('UPDATE %s SET %s%s', $this->quoteIdentifier($table), implode(', ', $sets), $whereSql);

        return [$sql, [...$setBindings, ...$whereBindings]];
    }

    /**
     * @param array<int, array{type:'basic', boolean:string, column:string, operator:string, param:string}|array{type:'raw', boolean:string, sql:string, bindings?:mixed}|array{type:'in', boolean:string, column:string, not:bool, params:array<int, string>}> $wheres
     * @param array<string, mixed> $bindings
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    public function buildDeleteSql(string $table, array $wheres, array $bindings): array
    {
        [$whereSql, $whereBindings] = $this->compileWhere($wheres, $bindings);
        $sql = sprintf('DELETE FROM %s%s', $this->quoteIdentifier($table), $whereSql);

        return [$sql, $whereBindings];
    }

    public function normalizeOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'];

        if (!in_array($operator, $allowed, true)) {
            throw new RuntimeException('Unsupported SQL operator: ' . $operator);
        }

        return $operator;
    }
}
