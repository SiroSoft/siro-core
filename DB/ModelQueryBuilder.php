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

    public function run(): array
    {
        $this->applySoftDeleteFilter();
        return parent::get();
    }

    public function runFirst(): ?array
    {
        $this->applySoftDeleteFilter();

        $clone = clone $this;
        $clone->limit(1);
        $rows = parent::get();
        $first = $rows[0] ?? null;

        return $first;
    }

    public function runPaginate(int $perPage = 20, int $page = 0): array
    {
        $this->applySoftDeleteFilter();
        return parent::paginate($perPage, $page);
    }

    public function count(string $column = '*'): int
    {
        $this->applySoftDeleteFilter();
        return parent::count($column);
    }

    private function applySoftDeleteFilter(): void
    {
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
}
