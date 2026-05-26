<?php

declare(strict_types=1);

namespace Siro\Core\DB\Relations;

use Siro\Core\Database;
use Siro\Core\DB\ModelQueryBuilder;
use Siro\Core\Model;

class BelongsToMany
{
    /** @var array<int, Model> */
    private array $cachedResults = [];

    /** @var array<int, string> Extra pivot columns to include (e.g. ['quantity', 'price']) */
    private array $pivotColumns;

    public function __construct(
        private readonly string $relatedClass,
        private readonly string $pivotTable,
        private readonly string $foreignKey,
        private readonly string $relatedKey,
        private readonly string $localKey,
        private readonly int|string $localValue,
    ) {
    }

    /**
     * Specify extra pivot columns to retrieve.
     * @param array<int, string> $columns
     */
    public function withPivot(array $columns): static
    {
        $this->pivotColumns = $columns;
        return $this;
    }

    /**
     * Quote identifier for SQL safety.
     */
    public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '*') {
            return $identifier;
        }

        // BLOCK dangerous characters to prevent SQL injection
        if (preg_match('/[^a-zA-Z0-9_.\s\-]/', $identifier)) {
            throw new \RuntimeException('Invalid identifier: contains illegal characters');
        }

        // Prevent multi-statement injection
        if (stripos($identifier, ';') !== false ||
            stripos($identifier, '--') !== false ||
            stripos($identifier, '/*') !== false) {
            throw new \RuntimeException('Invalid identifier: SQL injection attempt detected');
        }

        if (str_contains($identifier, '(') || str_contains($identifier, ')')) {
            throw new \RuntimeException('Invalid identifier: function calls and parentheses not allowed');
        }

        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
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

    /**
     * @return array<int, Model>
     */
    public function get(): array
    {
        if ($this->cachedResults !== []) {
            return $this->cachedResults;
        }

        return $this->query()->get();
    }

    public function query(): ModelQueryBuilder
    {
        /** @var Model $related */
        $related = new $this->relatedClass();
        $relatedTable = $related->getTable();
        $pivotTable = $this->pivotTable;
        $foreignKey = $this->foreignKey;
        $relatedKey = $this->relatedKey;
        $localValue = $this->localValue;

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->relatedClass();
        $select = ["{$relatedTable}.*"];

        // Include extra pivot columns in select
        foreach ($this->pivotColumns as $col) {
            $select[] = "{$pivotTable}.{$col}";
        }

        /** @var ModelQueryBuilder $query */
        $query = $relatedInstance
            ->query()
            ->select(...$select)
            ->join("{$pivotTable}", "{$pivotTable}.{$relatedKey}", '=', "{$relatedTable}.id")
            ->where("{$pivotTable}.{$foreignKey}", '=', $localValue);
        return $query;
    }

    /** @return array<int, string> */
    public function getPivotColumns(): array { return $this->pivotColumns; }
    public function getRelatedClass(): string { return $this->relatedClass; }
    public function getPivotTable(): string { return $this->pivotTable; }
    public function getForeignKey(): string { return $this->foreignKey; }
    public function getRelatedKey(): string { return $this->relatedKey; }
    public function getLocalKey(): string { return $this->localKey; }

    /**
     * @param array<string, mixed> $pivotData
     */
    public function attach(int|string $relatedId, array $pivotData = []): void
    {
        $pivotTable = $this->quoteIdentifier($this->pivotTable);
        $foreignKey = $this->quoteIdentifier($this->foreignKey);
        $relatedKey = $this->quoteIdentifier($this->relatedKey);

        $exists = Database::select(
            "SELECT COUNT(*) FROM {$pivotTable} WHERE {$foreignKey} = ? AND {$relatedKey} = ?",
            [$this->localValue, $relatedId]
        );

        if (($exists[0]['COUNT(*)'] ?? 0) === 0) {
            $columns = [$this->foreignKey, $this->relatedKey];
            $placeholders = ['?', '?'];
            $values = [$this->localValue, $relatedId];

            foreach ($pivotData as $col => $val) {
                $columns[] = $col;
                $placeholders[] = '?';
                $values[] = $val;
            }

            $colList = implode(', ', array_map(fn(string $c) => $this->quoteIdentifier($c), $columns));
            $phList = implode(', ', $placeholders);

            Database::execute(
                "INSERT INTO {$pivotTable} ({$colList}) VALUES ({$phList})",
                $values
            );
        }
    }

    public function detach(int|string $relatedId): void
    {
        $pivotTable = $this->quoteIdentifier($this->pivotTable);
        $foreignKey = $this->quoteIdentifier($this->foreignKey);
        $relatedKey = $this->quoteIdentifier($this->relatedKey);

        Database::execute(
            "DELETE FROM {$pivotTable} WHERE {$foreignKey} = ? AND {$relatedKey} = ?",
            [$this->localValue, $relatedId]
        );
    }

    public function detachAll(): void
    {
        $pivotTable = $this->quoteIdentifier($this->pivotTable);
        $foreignKey = $this->quoteIdentifier($this->foreignKey);

        Database::execute(
            "DELETE FROM {$pivotTable} WHERE {$foreignKey} = ?",
            [$this->localValue]
        );
    }

    /**
     * @param array<int, int|string|array<string, mixed>> $relatedIds
     *        Numeric array: sync([1, 2, 3])
     *        Associative: sync([1 => ['quantity' => 5], 2 => ['quantity' => 3]])
     */
    public function sync(array $relatedIds): void
    {
        $current = Database::select(
            "SELECT {$this->relatedKey} FROM {$this->quoteIdentifier($this->pivotTable)} WHERE {$this->quoteIdentifier($this->foreignKey)} = ?",
            [$this->localValue]
        );
        $existingIds = [];
        foreach ($current as $row) {
            $val = $row[$this->relatedKey] ?? null;
            if (is_int($val) || is_string($val)) {
                $existingIds[] = $val;
            }
        }

        $flatIds = [];
        $pivotDataMap = [];
        foreach ($relatedIds as $key => $val) {
            if (is_array($val)) {
                $flatIds[] = $key;
                $pivotDataMap[$key] = $val;
            } else {
                $flatIds[] = $val;
            }
        }

        $toDetach = array_diff($existingIds, $flatIds);
        $toAttach = array_diff($flatIds, $existingIds);

        foreach ($toDetach as $id) {
            $this->detach($id);
        }
        foreach ($toAttach as $id) {
            $data = $pivotDataMap[$id] ?? [];
            $this->attach($id, $data);
        }
    }

    public function has(int|string $relatedId): bool
    {
        $pivotTable = $this->quoteIdentifier($this->pivotTable);
        $foreignKey = $this->quoteIdentifier($this->foreignKey);
        $relatedKey = $this->quoteIdentifier($this->relatedKey);

        /** @var array<int, array<string, mixed>> $exists */
        $exists = Database::select(
            "SELECT COUNT(*) FROM {$pivotTable} WHERE {$foreignKey} = ? AND {$relatedKey} = ?",
            [$this->localValue, $relatedId]
        );

        return ($exists[0]['COUNT(*)'] ?? 0) > 0;
    }

    public function toggle(int|string $relatedId): void
    {
        if ($this->has($relatedId)) {
            $this->detach($relatedId);
        } else {
            $this->attach($relatedId);
        }
    }

    /**
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->query()->{$method}(...$parameters);
    }
}
