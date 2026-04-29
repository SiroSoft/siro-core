<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use RuntimeException;
use Siro\Core\Model;

final class ModelQueryBuilder extends QueryBuilder
{
    private string $modelClass;
    private bool $withSoftDeleted = false;
    private bool $onlySoftDeleted = false;
    private bool $softDeleteFilterApplied = false;

    public function __construct(string $table, string $modelClass)
    {
        parent::__construct($table);
        $this->modelClass = $modelClass;
    }

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

    public function withoutSoftDeleteFilter(): self
    {
        $this->withSoftDeleted = true;
        return $this;
    }

    public function onlySoftDeleted(): self
    {
        $this->onlySoftDeleted = true;
        return $this;
    }

    /** @return array<int, Model> */
    public function get(): array
    {
        $this->applySoftDeleteFilter();
        $rows = parent::get();

        return array_map(fn (array $row): Model => $this->hydrate($row), $rows);
    }

    /** @return array{data: array<int, Model>, meta: array{page:int, per_page:int, total:int, last_page:int}} */
    public function paginate(int $perPage = 20, ?int $page = null): array
    {
        $this->applySoftDeleteFilter();
        $result = parent::paginate($perPage, $page);

        $result['data'] = array_map(
            fn (array $row): Model => $this->hydrate($row),
            $result['data']
        );

        return $result;
    }

    public function count(string $column = '*'): int
    {
        $this->applySoftDeleteFilter();
        return parent::count($column);
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

        if ($this->withSoftDeleted || $this->onlySoftDeleted) {
            if ($this->onlySoftDeleted) {
                $this->whereRaw('deleted_at IS NOT NULL');
            }
            return;
        }

        $modelClass = $this->modelClass;
        $uses = class_uses($modelClass) ?: [];
        if (in_array(SoftDeletes::class, $uses, true)) {
            $this->whereRaw('deleted_at IS NULL');
        }
    }

    private function hydrate(array $row): Model
    {
        $modelClass = $this->modelClass;
        return $modelClass::hydrate($row);
    }
}
