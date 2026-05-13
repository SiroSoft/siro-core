<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use RuntimeException;
use Siro\Core\Model;

if (!function_exists(__NAMESPACE__ . '\class_uses_recursive')) {
    function class_uses_recursive(object|string $class): array
    {
        $traits = [];
        do {
            $traits += \class_uses($class);
        } while ($class = \get_parent_class($class));

        foreach ($traits as $trait => $same) {
            $traits += \class_uses($trait);
        }

        return $traits;
    }
}

final class ModelQueryBuilder extends QueryBuilder
{
    private string $modelClass;
    private bool $withSoftDeleted = false;
    private bool $onlySoftDeleted = false;
    private bool $softDeleteFilterApplied = false;

    /** @var array<string, array<int, string>> */
    private array $eagerLoads = [];

    public function __construct(string $table, string $modelClass)
    {
        parent::__construct($table);
        $this->modelClass = $modelClass;
    }

    public function find(int|string $id): ?\Siro\Core\Model
    {
        return $this->where('id', '=', $id)->first();
    }

    /**
     * @param array<int, string> $columns
     */
    public function eagerLoad(string $relation, array $columns = ['*']): self
    {
        $this->eagerLoads[$relation] = $columns;
        return $this;
    }

    /** @param array<int, mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        $modelClass = $this->modelClass;
        $scopeMethod = 'scope' . ucfirst($method);

        if (method_exists($modelClass, $scopeMethod)) {
            $model = new $modelClass();
            $model->{$scopeMethod}($this, ...$parameters);
            return $this;
        }

        throw new RuntimeException(sprintf('Scope %s not found on %s.', $method, $modelClass));
    }

    public function limit(int $limit): static
    {
        parent::limit($limit);
        return $this;
    }

    public function offset(int $offset): static
    {
        parent::offset($offset);
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        parent::orderBy($column, $direction);
        return $this;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        parent::where($column, $operatorOrValue, $value);
        return $this;
    }

    public function select(array|string ...$columns): static
    {
        parent::select(...$columns);
        return $this;
    }

    public function withTrashed(): self
    {
        $this->withSoftDeleted = true;
        return $this;
    }

    public function withoutSoftDeleteFilter(): self
    {
        return $this->withTrashed();
    }

    public function onlySoftDeleted(): self
    {
        $this->onlySoftDeleted = true;
        return $this;
    }

    public function onlyTrashed(): self
    {
        return $this->onlySoftDeleted();
    }

    /** @return array<int, Model> */
    // @phpstan-ignore-next-line return.type
    public function get(): array
    {
        $this->applySoftDeleteFilter();
        $rows = parent::get();
        $models = $this->hydrateModels($rows);

        if ($this->eagerLoads !== []) {
            $loader = new \Siro\Core\DB\EagerLoader($this->modelClass);
            $loader->loadBatch($models, $this->eagerLoads);
        }

        return $models;
    }

    public function first(): ?Model
    {
        $this->applySoftDeleteFilter();
        $clone = clone $this;
        $clone->limit(1);
        return ($clone->get())[0] ?? null;
    }

    /** @return array{data: array<int, Model>, meta: array{page:int, per_page:int, total:int, last_page:int}} */
    public function paginate(int $perPage = 20, ?int $page = null): array
    {
        $this->applySoftDeleteFilter();

        $total = $this->count();
        $perPage = max(1, $perPage);
        $page = max(1, $page ?? 1);
        $offset = ($page - 1) * $perPage;
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        // Use parent get() which returns raw arrays (avoid re-hydration from $this->get())
        $this->limit($perPage)->offset($offset);
        $rawRows = parent::get();

        $models = $this->hydrateModels($rawRows);

        if ($this->eagerLoads !== []) {
            $loader = new \Siro\Core\DB\EagerLoader($this->modelClass);
            $loader->loadBatch($models, $this->eagerLoads);
        }

        return [
            'data' => $models,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    public function count(string $column = '*'): int
    {
        $this->applySoftDeleteFilter();
        return parent::count($column);
    }

    /**
     * Cursor-based pagination returning Model instances.
     *
     * @return array<string, mixed>
     */
    public function cursorPaginate(int $perPage = 15, ?array $cursor = null, string $order = 'asc'): array
    {
        $this->applySoftDeleteFilter();

        $rawResult = parent::cursorPaginate($perPage, $cursor, $order);
        $models = $this->hydrateModels($rawResult['data']);

        if ($this->eagerLoads !== []) {
            $loader = new \Siro\Core\DB\EagerLoader($this->modelClass);
            $loader->loadBatch($models, $this->eagerLoads);
        }

        /** @var array<string, mixed> */
        $result = [
            'data' => $models,
            'meta' => $rawResult['meta'],
            'next_cursor' => $rawResult['next_cursor'],
        ];

        return $result;
    }

    public function sum(string $column): float|int
    {
        $this->applySoftDeleteFilter();
        return parent::sum($column);
    }

    public function avg(string $column): float|int
    {
        $this->applySoftDeleteFilter();
        return parent::avg($column);
    }

    private function applySoftDeleteFilter(): void
    {
        if ($this->softDeleteFilterApplied) {
            return;
        }
        $this->softDeleteFilterApplied = true;

        if ($this->withSoftDeleted) {
            return;
        }
        if ($this->onlySoftDeleted) {
            $this->whereRaw('deleted_at IS NOT NULL');
            return;
        }

        $uses = class_uses_recursive($this->modelClass) ?: [];
        if (in_array(\Siro\Core\DB\SoftDeletes::class, $uses, true)) {
            $this->whereRaw('deleted_at IS NULL');
        }
    }

    /**
     * Hydrate a single row into a model instance.
     *
     * @param array<string, mixed> $row
     * @return Model
     */
    private function hydrateModel(array $row): Model
    {
        $modelClass = $this->modelClass;
        return $modelClass::hydrate($row);
    }

    /**
     * Hydrate multiple rows into model instances.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, Model>
     */
    private function hydrateModels(array $rows): array
    {
        return array_map(fn (array $row): Model => $this->hydrateModel($row), $rows);
    }
}
