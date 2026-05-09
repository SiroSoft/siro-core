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
     * Quote identifier for SQL safety.
     */
    private function quoteIdentifier(string $identifier): string
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

        // Don't quote function calls or expressions with parentheses
        if (str_contains($identifier, '(')) {
            return $identifier;
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
        $related = new $this->relatedClass();
        $relatedTable = $related->getTable();
        $pivotTable = $this->pivotTable;
        $foreignKey = $this->foreignKey;
        $relatedKey = $this->relatedKey;
        $localValue = $this->localValue;

        return (new $this->relatedClass())
            ->query()
            ->select("{$relatedTable}.*")
            ->join("{$pivotTable}", "{$pivotTable}.{$relatedKey}", '=', "{$relatedTable}.id")
            ->where("{$pivotTable}.{$foreignKey}", '=', $localValue);
    }

    public function getRelatedClass(): string { return $this->relatedClass; }
    public function getPivotTable(): string { return $this->pivotTable; }
    public function getForeignKey(): string { return $this->foreignKey; }
    public function getRelatedKey(): string { return $this->relatedKey; }
    public function getLocalKey(): string { return $this->localKey; }

    public function attach(int|string $relatedId): void
    {
        $pivotTable = $this->quoteIdentifier($this->pivotTable);
        $foreignKey = $this->quoteIdentifier($this->foreignKey);
        $relatedKey = $this->quoteIdentifier($this->relatedKey);

        $exists = Database::select(
            "SELECT COUNT(*) FROM {$pivotTable} WHERE {$foreignKey} = ? AND {$relatedKey} = ?",
            [$this->localValue, $relatedId]
        );

        if (($exists[0]['COUNT(*)'] ?? 0) === 0) {
            Database::execute(
                "INSERT INTO {$pivotTable} ({$foreignKey}, {$relatedKey}) VALUES (?, ?)",
                [$this->localValue, $relatedId]
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
     * @param array<int, int|string> $relatedIds
     */
    public function sync(array $relatedIds): void
    {
        Database::transaction(function () use ($relatedIds) {
            $this->detachAll();
            foreach ($relatedIds as $relatedId) {
                $this->attach($relatedId);
            }
        });
    }

    public function has(int|string $relatedId): bool
    {
        $pivotTable = $this->quoteIdentifier($this->pivotTable);
        $foreignKey = $this->quoteIdentifier($this->foreignKey);
        $relatedKey = $this->quoteIdentifier($this->relatedKey);

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
