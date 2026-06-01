<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use RuntimeException;
use Siro\Core\Database;
use Siro\Core\Model;

if (!function_exists(__NAMESPACE__ . '\class_uses_recursive')) {
    /** @return array<string, string> */
    function class_uses_recursive(object|string $class): array
    {
        $traits = [];
        do {
            $t = \class_uses($class);
            $traits += is_array($t) ? $t : [];
        } while ($class = \get_parent_class($class));

        foreach ($traits as $trait => $same) {
            $t = \class_uses($trait);
            $traits += is_array($t) ? $t : [];
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

    /** @var array<string, array<string, mixed>> */
    public array $withCounts = [];

    /** @var array<string, array<string, string>> */
    private static array $classUsesCache = [];

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

    /**
     * Add relation count to the query results.
     *
     * @param string|array<string, (callable(ModelQueryBuilder): void)|null> $relation
     * Usage:
     *   User::withCount('posts')
     *   User::withCount('posts as post_count')
     *   User::withCount(['posts', 'comments' => fn($q) => $q->where('approved', true)])
     */
    public function withCount(string|array $relation, ?callable $callback = null): self
    {
        if (is_string($relation)) {
            $alias = $relation;
            $name = $relation;
            if (str_contains($relation, ' as ')) {
                $parts = explode(' as ', $relation);
                $name = trim($parts[0]);
                $alias = trim($parts[1]);
            }
            $this->withCounts[$name] = [
                'alias' => $alias,
                'callback' => $callback,
            ];
        } elseif (is_array($relation)) {
            foreach ($relation as $key => $value) {
                if (is_string($value)) {
                    $this->withCount($value);
                } elseif (is_callable($value)) {
                    $this->withCounts[(string) $key] = [
                        'alias' => (string) $key,
                        'callback' => $value,
                    ];
                }
            }
        }
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

        // Proxy to parent QueryBuilder for methods like whereNull, whereRaw, whereIn, etc.
        if (method_exists(QueryBuilder::class, $method)) {
            $this->$method(...$parameters);
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

    public function orderBy(string|\Siro\Core\DB\RawExpression $column, string $direction = 'asc'): static
    {
        parent::orderBy($column, $direction);
        return $this;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            parent::where($column, $operatorOrValue);
        } else {
            parent::where($column, $operatorOrValue, $value);
        }
        return $this;
    }

    /**
     * Add a where clause to filter by relation existence.
     *
     * Supports nested dot-notation: whereHas('user.comments', fn($q) => $q->where('approved', true))
     */
    public function whereHas(string $relation, ?callable $callback = null, string $boolean = 'AND'): static
    {
        $segments = explode('.', $relation);
        $this->buildNestedExists($segments, $callback, $boolean);
        return $this;
    }

    public function orWhereHas(string $relation, ?callable $callback = null): static
    {
        return $this->whereHas($relation, $callback, 'OR');
    }

    /**
     * Add a where clause to filter by relation absence.
     */
    public function whereDoesntHave(string $relation, ?callable $callback = null): static
    {
        $this->whereHas($relation, $callback, 'AND');
        $lastKey = array_key_last($this->wheres);
        if ($lastKey !== null) {
            $last = $this->wheres[$lastKey];
            if (isset($last['sql'])) {
                $this->wheres[$lastKey] = [
                    'type' => 'raw',
                    'boolean' => $last['boolean'] === 'OR' ? 'OR' : 'AND',
                    'sql' => 'NOT (' . $last['sql'] . ')',
                ];
            }
        }
        return $this;
    }

    public function orWhereDoesntHave(string $relation, ?callable $callback = null): static
    {
        $this->whereHas($relation, $callback, 'OR');
        $lastKey = array_key_last($this->wheres);
        if ($lastKey !== null) {
            $last = $this->wheres[$lastKey];
            if (isset($last['sql'])) {
                $this->wheres[$lastKey] = [
                    'type' => 'raw',
                    'boolean' => 'OR',
                    'sql' => 'NOT (' . $last['sql'] . ')',
                ];
            }
        }
        return $this;
    }

    /**
     * Add a has clause (relation count condition).
     *
     * Examples:
     *   User::has('posts')              -> EXISTS (SELECT 1 FROM posts WHERE ...)
     *   User::has('posts', '>=', 3)     -> (SELECT COUNT(*) FROM posts WHERE ...) >= 3
     */
    public function has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'AND'): static
    {
        $segments = explode('.', $relation);
        $model = $this->newModelInstance();
        $currentTable = $model->getTable();

        $segCount = count($segments);

        if ($segCount > 1) {
            throw new \RuntimeException('Nested has() is not supported yet. Use whereHas() for nested relations.');
        }

        $relName = $segments[0];
        $rel = $this->resolveRelation(method_exists($model, $relName) ? $model->{$relName}() : null);
        if ($rel === null) {
            throw new \RuntimeException("Relation '{$relName}' not found on " . $this->modelClass);
        }
        $relatedModel = $this->resolveRelationModel($rel);
        $relTable = $relatedModel->getTable();

        [$cond, $relQuery] = $this->buildRelationCondition($rel, $model, $currentTable, $relTable, $relName);

        // For simple existence check (>= 1), use EXISTS
        if ($operator === '>=' && $count === 1) {
            $qb = $relQuery;
            $qb->selectRaw('1')->whereRaw($cond);
            $subSql = $qb->toSql();
            $this->wheres[] = [
                'type' => 'raw',
                'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
                'sql' => 'EXISTS (' . $subSql . ')',
            ];
            return $this;
        }

        // For count conditions, use (SELECT COUNT(*) ... HAVING ...) >= count
        $qb = $relQuery;
        $qb->whereRaw($cond);
        $subSql = $qb->toSql();
        $disallowed = ['!=', '<>'];
        $safeOp = in_array($operator, $disallowed, true) ? $operator : '>=';
        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'sql' => '(SELECT COUNT(*) FROM (' . $subSql . ') AS siro_has_count) ' . $safeOp . ' ' . $count,
        ];
        return $this;
    }

    public function orHas(string $relation, string $operator = '>=', int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'OR');
    }

    /**
     * @param array<int, string> $segments
     */
    private function buildNestedExists(array $segments, ?callable $callback, string $boolean): void
    {
        $model = $this->newModelInstance();
        $currentTable = $model->getTable();

        $segCount = count($segments);

        if ($segCount <= 1) {
            $relName = $segments[0];
            $rel = $this->resolveRelation(method_exists($model, $relName) ? $model->{$relName}() : null);
            if ($rel === null) {
                throw new \RuntimeException("Relation '{$relName}' not found on " . $this->modelClass);
            }
            $relatedModel = $this->resolveRelationModel($rel);
            $relTable = $relatedModel->getTable();

            [$cond, $relQuery] = $this->buildRelationCondition($rel, $model, $currentTable, $relTable, $relName);
            $relQuery->selectRaw('1')->whereRaw($cond);

            if ($callback !== null) {
                $callback($relQuery);
            }

            $subSql = $relQuery->toSql();
            $this->wheres[] = [
                'type' => 'raw',
                'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
                'sql' => 'EXISTS (' . $subSql . ')',
            ];
            return;
        }

        $relations = [];
        $resolved = [];

        for ($i = 0; $i < $segCount; $i++) {
            $relName = $segments[$i];
            $srcModel = $i === 0 ? $model : $resolved[$i - 1];
            $rawRel = method_exists($srcModel, $relName) ? $srcModel->{$relName}() : null;
            $relations[$i] = $rawRel;
            $rel = $this->resolveRelation($rawRel);
            if ($rel === null) {
                throw new \RuntimeException("Relation '{$relName}' not found in nested path");
            }
            $resolved[$i] = $this->resolveRelationModel($rel);
        }

        $innerQb = $resolved[$segCount - 1]->query();
        $innerTable = $resolved[$segCount - 1]->getTable();
        $prevTable = $resolved[$segCount - 2]->getTable();
        $prevModel = $resolved[$segCount - 2];
        /** @var \Siro\Core\DB\Relations\HasOne|\Siro\Core\DB\Relations\HasMany|\Siro\Core\DB\Relations\BelongsTo|\Siro\Core\DB\Relations\BelongsToMany $lastRel */
        $lastRel = $this->resolveRelation($relations[$segCount - 1]);
        [$innerCond,] = $this->buildRelationCondition($lastRel, $prevModel, $prevTable, $innerTable, $segments[$segCount - 1]);

        $innerQb->selectRaw('1')->whereRaw($innerCond);
        if ($callback !== null) {
            $callback($innerQb);
        }

        $subSql = $innerQb->toSql();
        $fullSql = 'EXISTS (' . $subSql . ')';

        for ($i = $segCount - 2; $i >= 0; $i--) {
            /** @var \Siro\Core\Model $relModel */
            $relModel = $resolved[$i];
            $relTable = $relModel->getTable();
            $parentModel = $i > 0 ? $resolved[$i - 1] : $model;
            $parentTable = $i > 0 ? $resolved[$i - 1]->getTable() : $currentTable;

            /** @var \Siro\Core\DB\Relations\HasOne|\Siro\Core\DB\Relations\HasMany|\Siro\Core\DB\Relations\BelongsTo|\Siro\Core\DB\Relations\BelongsToMany $currentRel */
            $currentRel = $this->resolveRelation($relations[$i]);
            [$cond,] = $this->buildRelationCondition($currentRel, $parentModel, $parentTable, $relTable, $segments[$i]);

            $outerQb = $relModel->query();
            $outerQb->selectRaw('1')->whereRaw($cond);
            $outerQb->wheres[] = [
                'type' => 'raw',
                'boolean' => 'AND',
                'sql' => $fullSql,
            ];
            $fullSql = 'EXISTS (' . $outerQb->toSql() . ')';
        }

        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'sql' => $fullSql,
        ];
    }

    private function newModelInstance(): \Siro\Core\Model
    {
        /** @var \Siro\Core\Model $instance */
        $instance = new ($this->modelClass)();
        return $instance;
    }

    /**
     * Resolve the related model from a relation object.
     */
    /**
     * @return \Siro\Core\DB\Relations\HasOne|\Siro\Core\DB\Relations\HasMany|\Siro\Core\DB\Relations\BelongsTo|\Siro\Core\DB\Relations\BelongsToMany|null
     */
    private function resolveRelation(mixed $rel): mixed
    {
        if ($rel instanceof \Siro\Core\DB\Relations\HasOne
            || $rel instanceof \Siro\Core\DB\Relations\HasMany
            || $rel instanceof \Siro\Core\DB\Relations\BelongsTo
            || $rel instanceof \Siro\Core\DB\Relations\BelongsToMany
        ) {
            return $rel;
        }
        return null;
    }

    private function resolveRelationModel(object $rel): \Siro\Core\Model
    {
        $class = match (true) {
            $rel instanceof \Siro\Core\DB\Relations\HasOne => $rel->getRelatedClass(),
            $rel instanceof \Siro\Core\DB\Relations\HasMany => $rel->getRelatedClass(),
            $rel instanceof \Siro\Core\DB\Relations\BelongsTo => $rel->getRelatedClass(),
            $rel instanceof \Siro\Core\DB\Relations\BelongsToMany => $rel->getRelatedClass(),
            default => throw new \RuntimeException('Unknown relation type: ' . $rel::class),
        };
        /** @var \Siro\Core\Model $instance */
        $instance = new $class();
        return $instance;
    }

    /**
     * Build the JOIN condition for a relation.
     *
     * @param \Siro\Core\DB\Relations\HasOne|\Siro\Core\DB\Relations\HasMany|\Siro\Core\DB\Relations\BelongsTo|\Siro\Core\DB\Relations\BelongsToMany $rel
     * @return array{0: string, 1: ModelQueryBuilder}
     */
    private function buildRelationCondition(object $rel, \Siro\Core\Model $parentModel, string $parentTable, string $relTable, string $relName): array
    {
        if ($rel instanceof \Siro\Core\DB\Relations\BelongsTo) {
            /** @var class-string<\Siro\Core\Model> $relClass */
            $relClass = $rel->getRelatedClass();
            $query = (new $relClass())->query();
            /** @var ModelQueryBuilder $query */
            return [
                "{$relTable}.{$rel->getOwnerKey()} = {$parentTable}.{$rel->getForeignKey()}",
                $query,
            ];
        }

        if ($rel instanceof \Siro\Core\DB\Relations\HasOne || $rel instanceof \Siro\Core\DB\Relations\HasMany) {
            /** @var class-string<\Siro\Core\Model> $relClass */
            $relClass = $rel->getRelatedClass();
            $query = (new $relClass())->query();
            /** @var ModelQueryBuilder $query */
            return [
                "{$relTable}.{$rel->getForeignKey()} = {$parentTable}.{$rel->getLocalKey()}",
                $query,
            ];
        }

        if ($rel instanceof \Siro\Core\DB\Relations\BelongsToMany) {
            /** @var class-string<\Siro\Core\Model> $relClass */
            $relClass = $rel->getRelatedClass();
            $query = (new $relClass())->query();
            /** @var ModelQueryBuilder $query */
            return [
                "{$relTable}.{$rel->getRelatedKey()} IN (SELECT {$rel->getRelatedKey()} FROM {$rel->getPivotTable()} WHERE {$rel->getForeignKey()} = {$parentTable}.id)",
                $query,
            ];
        }

        // @phpstan-ignore deadCode.unreachable
        throw new \RuntimeException("Cannot build condition for relation: {$relName}");
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

        if ($rows === []) {
            return [];
        }

        /** @var array<int, Model> $models */
        $models = $this->hydrateModels($rows);

        // Merge model's $with property into query eager loads
        $allEagerLoads = $this->eagerLoads;
        $withRelations = $this->modelClass !== '' && property_exists($this->modelClass, 'with')
            ? (array) (new $this->modelClass())->with
            : [];
        foreach ($withRelations as $relation) {
            $relName = is_string($relation) ? $relation : (is_array($relation) ? key($relation) : '');
            if ($relName !== '' && !isset($allEagerLoads[$relName])) {
                $allEagerLoads[$relName] = ['*'];
            }
        }

        if ($allEagerLoads !== []) {
            $loader = new \Siro\Core\DB\EagerLoader($this->modelClass);
            $loader->loadBatch($models, $allEagerLoads);
        }

        if ($this->withCounts !== []) {
            $this->loadCountsIntoModels($models);
        }

        return $models;
    }

    /**
     * Memory-efficient model streaming.
     *
     * Yields hydrated Model instances one at a time without
     * loading all results into memory. Use for large datasets
     * where loading everything at once would exceed memory limits.
     *
     * @return \Generator<int, Model>
     */
    // @phpstan-ignore-next-line return.childReturnType
    public function cursor(): \Generator
    {
        $this->applySoftDeleteFilter();
        foreach (parent::cursor() as $row) {
            yield $this->hydrateModel($row);
        }
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

    /**
     * @param array<int, Model> $models
     */
    public function loadCountsIntoModels(array $models): void
    {
        if ($models === []) {
            return;
        }

        $modelInstance = $this->newModelInstance();

        foreach ($this->withCounts as $relation => $config) {
            /** @var array{alias?: string, callback?: \Closure|null} $config */
            $alias = (string) ($config['alias'] ?? $relation);
            $callback = $config['callback'] ?? null;

            if (!method_exists($modelInstance, $relation)) {
                continue;
            }

            $rel = $this->resolveRelation(method_exists($modelInstance, $relation) ? $modelInstance->{$relation}() : null);
            if ($rel === null) {
                continue;
            }
            $relatedModel = $this->resolveRelationModel($rel);
            $relTable = $relatedModel->getTable();

            [$cond] = $this->buildRelationCondition($rel, $modelInstance, $modelInstance->getTable(), $relTable, $relation);

            $localIds = [];
            $idKey = 'id';
            if ($rel instanceof \Siro\Core\DB\Relations\BelongsTo) {
                $idKey = $rel->getForeignKey();
            } elseif ($rel instanceof \Siro\Core\DB\Relations\HasOne || $rel instanceof \Siro\Core\DB\Relations\HasMany) {
                $idKey = $rel->getLocalKey();
            }
            foreach ($models as $m) {
                $id = $m->getAttribute($idKey);
                if (is_numeric($id) || is_string($id)) {
                    $localIds[] = $id;
                }
            }

            $localIds = array_unique($localIds);
            if ($localIds === []) {
                continue;
            }

            // Determine the grouping column
            $groupCol = $rel instanceof \Siro\Core\DB\Relations\BelongsTo
                ? $relTable . '.' . $rel->getOwnerKey()
                : $relTable . '.' . $rel->getForeignKey();

            // Build count query: SELECT fk, COUNT(*) as count FROM related WHERE fk IN (...) GROUP BY fk
            $qb = $relatedModel->query();
            $qb->selectRaw("{$groupCol} AS siro_fk, COUNT(*) AS siro_count");
            $placeholders = [];
            $bindings = [];
            foreach ($localIds as $i => $lid) {
                $key = 'sc_' . $i;
                $placeholders[] = ':' . $key;
                $bindings[$key] = is_string($lid) ? $lid : (string) $lid;
            }
            $qb->whereRaw("{$groupCol} IN (" . implode(', ', $placeholders) . ")", $bindings);
            $qb->groupByRaw("{$groupCol}");

            if ($callback !== null) {
                $callback($qb);
            }

            [$countSql, $countBindings] = $qb->toCompiled();
            $rows = Database::select($countSql, $countBindings);

            $counts = [];
            foreach ($rows as $row) {
                /** @var array<string, mixed> $row */
                $fkRaw = $row['siro_fk'] ?? 0;
                $fk = is_scalar($fkRaw) ? (string) $fkRaw : '0';
                $countRaw = $row['siro_count'] ?? 0;
                $counts[$fk] = is_numeric($countRaw) ? (int) $countRaw : 0;
            }

            $countKey = str_replace('.', '_', (string) $alias) . '_count';
            foreach ($models as $m) {
                $id = $rel instanceof \Siro\Core\DB\Relations\BelongsTo
                    ? $m->getAttribute($rel->getForeignKey())
                    : $m->getAttribute('id');
                $idStr = is_scalar($id) ? (string) $id : '0';
                $m->setAttribute($countKey, $counts[$idStr] ?? 0);
            }
        }
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

        $uses = self::$classUsesCache[$this->modelClass]
            ?? (self::$classUsesCache[$this->modelClass] = class_uses_recursive($this->modelClass) ?: []);
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
        /** @var Model $result */
        $result = $modelClass::hydrate($row);
        return $result;
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
