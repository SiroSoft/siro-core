<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use RuntimeException;
use Siro\Core\Cache;
use Siro\Core\Database;

final class SqlCompiler
{
    private ?string $connectionName = null;
    private string $table = '';

    public function setConnection(?string $name): void
    {
        $this->connectionName = $name;
    }

    public function setTable(string $table): void
    {
        $this->table = $table;
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
                self::$driverNames[$key] = Database::connection($connectionName ?? $this->connectionName)->getAttribute(\PDO::ATTR_DRIVER_NAME);
            } catch (\Throwable) {
                self::$driverNames[$key] = 'mysql';
            }
        }
        return self::$driverNames[$key];
    }

    public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '*') {
            return $identifier;
        }

        if (preg_match('/[^a-zA-Z0-9_.\s\-]/', $identifier)) {
            throw new \RuntimeException('Invalid identifier: contains illegal characters');
        }

        if (stripos($identifier, ';') !== false ||
            stripos($identifier, '--') !== false ||
            stripos($identifier, '/*') !== false) {
            throw new \RuntimeException('Invalid identifier: SQL injection attempt detected');
        }

        if (str_contains($identifier, '(')) {
            return $identifier;
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

        return implode('.', $parts);
    }

    public function quoteColumnList(string $columns): string
    {
        $parts = explode(',', $columns);
        foreach ($parts as $i => $part) {
            $parts[$i] = $this->quoteIdentifier(trim($part));
        }
        return implode(', ', $parts);
    }

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
    ): array {
        [$whereSql, $whereBindings] = $this->compileWhere($wheres, $bindings);
        [$havingSql, $havingBindings] = $this->compileHaving($havings, $bindings);

        $quotedColumns = $this->quoteColumnList(implode(', ', $columns));
        $sql = sprintf('SELECT %s FROM %s', $quotedColumns, $this->quoteIdentifier($table));
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

        return [$sql, [...$whereBindings, ...$havingBindings]];
    }

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

    public function compileJoins(array $joins): string
    {
        if ($joins === []) {
            return '';
        }

        $parts = [];
        foreach ($joins as $join) {
            $parts[] = sprintf(
                ' %s JOIN %s ON %s %s %s',
                $join['type'],
                $this->quoteIdentifier($join['table']),
                $this->quoteIdentifier($join['first']),
                $join['operator'],
                $this->quoteIdentifier($join['second'])
            );
        }

        return implode('', $parts);
    }

    public function compileGroupBy(array $groups): string
    {
        if ($groups === []) {
            return '';
        }

        $quoted = array_map(fn (string $col): string => $this->quoteIdentifier($col), $groups);
        return ' GROUP BY ' . implode(', ', $quoted);
    }

    public function compileOrderBy(array $orders): string
    {
        if ($orders === []) {
            return '';
        }

        $parts = [];
        foreach ($orders as $order) {
            $parts[] = $this->quoteIdentifier($order['column']) . ' ' . $order['direction'];
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    public function compileWhere(array $wheres, array $bindings): array
    {
        if ($wheres === []) {
            return ['', []];
        }

        $parts = [];
        $resultBindings = [];

        foreach ($wheres as $index => $where) {
            $prefix = $index === 0 ? '' : ' ' . $where['boolean'] . ' ';

            if (($where['type'] ?? 'basic') === 'raw') {
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

            if (($where['type'] ?? 'basic') === 'in') {
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

    public function compileHaving(array $havings, array $bindings): array
    {
        if ($havings === []) {
            return ['', []];
        }

        $parts = [];
        $resultBindings = [];

        foreach ($havings as $index => $having) {
            $prefix = $index === 0 ? '' : ' ' . $having['boolean'] . ' ';
            $parts[] = $prefix . $this->quoteIdentifier($having['column']) . ' ' . $having['operator'] . ' :' . $having['param'];
            $resultBindings[$having['param']] = $bindings[$having['param']];
        }

        return [' HAVING ' . implode('', $parts), $resultBindings];
    }

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
        $first = (string) ($parts !== false ? ($parts[0] ?? '') : '');
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
