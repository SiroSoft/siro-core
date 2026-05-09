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
        $exists = Database::select(
            "SELECT COUNT(*) FROM {$this->pivotTable} WHERE {$this->foreignKey} = ? AND {$this->relatedKey} = ?",
            [$this->localValue, $relatedId]
        );

        if (($exists[0]['COUNT(*)'] ?? 0) === 0) {
            Database::execute(
                "INSERT INTO {$this->pivotTable} ({$this->foreignKey}, {$this->relatedKey}) VALUES (?, ?)",
                [$this->localValue, $relatedId]
            );
        }
    }

    public function detach(int|string $relatedId): void
    {
        Database::execute(
            "DELETE FROM {$this->pivotTable} WHERE {$this->foreignKey} = ? AND {$this->relatedKey} = ?",
            [$this->localValue, $relatedId]
        );
    }

    public function detachAll(): void
    {
        Database::execute(
            "DELETE FROM {$this->pivotTable} WHERE {$this->foreignKey} = ?",
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
        $exists = Database::select(
            "SELECT COUNT(*) FROM {$this->pivotTable} WHERE {$this->foreignKey} = ? AND {$this->relatedKey} = ?",
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