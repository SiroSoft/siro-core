<?php

declare(strict_types=1);

namespace Siro\Core\DB;

use Siro\Core\Database;
use Siro\Core\Model;

/**
 * Eager loads relations for model instances (N+1 prevention).
 *
 * @package Siro\Core
 */
final class EagerLoader
{
    private string $modelClass;

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * @param array<int, Model> $models
     * @param array<string, array<int, string>> $eagerLoads
     */
    public function loadBatch(array $models, array $eagerLoads): void
    {
        if ($models === []) {
            return;
        }

        foreach ($eagerLoads as $relation => $columns) {
            $this->loadRelation($models, $relation, $columns);
        }
    }

    /**
     * Load relations on a single model.
     *
     * @param array<string, array<int, string>> $eagerLoads
     */
    public function load(Model $model, array $eagerLoads): void
    {
        $this->loadBatch([$model], $eagerLoads);
    }

    /**
     * @param array<int, Model> $models
     * @param array<int, string> $columns
     */
    private function loadRelation(array $models, string $relation, array $columns): void
    {
        $model = new $this->modelClass();
        if (!method_exists($model, $relation)) {
            return;
        }
        $rel = $model->{$relation}();

        if ($rel instanceof Relations\HasMany) {
            $this->loadHasMany($models, $rel, $relation, $columns);
        } elseif ($rel instanceof Relations\BelongsTo) {
            $this->loadBelongsTo($models, $rel, $relation, $columns);
        } elseif ($rel instanceof Relations\HasOne) {
            $this->loadHasOne($models, $rel, $relation, $columns);
        } elseif ($rel instanceof Relations\BelongsToMany) {
            $this->loadBelongsToMany($models, $rel, $relation, $columns);
        }
    }

    /**
     * @param array<int, Model> $models
     * @param array<int, string> $columns
     */
    private function loadHasOne(array $models, Relations\HasOne $rel, string $relation, array $columns): void
    {
        $relatedClass = $rel->getRelatedClass();
        $foreignKey = $rel->getForeignKey();
        $localKey = $rel->getLocalKey();

        $localIds = [];
        foreach ($models as $m) {
            $id = $m->{$localKey};
            if ($id !== null) {
                $localIds[] = $id;
            }
        }

        if ($localIds === []) {
            return;
        }

        $localIds = array_unique($localIds);

        $query = (new $relatedClass())->query();
        if ($columns !== ['*']) {
            $selectCols = array_merge([$foreignKey], array_diff($columns, [$foreignKey]));
            $query->select(...$selectCols);
        }
        $rows = $query->whereIn($foreignKey, $localIds)->limit(count($localIds))->get();

        $indexed = [];
        foreach ($rows as $row) {
            /** @var Model $row */
            $fk = (int) ($row->{$foreignKey} ?? 0);
            $indexed[$fk] = $row;
        }

        foreach ($models as $m) {
            $id = $m->{$localKey};
            $m->setRelation($relation, $indexed[(int) ($id ?? 0)] ?? null);
        }
    }

    /**
     * @param array<int, Model> $models
     * @param array<int, string> $columns
     */
    private function loadBelongsToMany(array $models, Relations\BelongsToMany $rel, string $relation, array $columns): void
    {
        $relatedClass = $rel->getRelatedClass();
        $pivotTable = $rel->getPivotTable();
        $foreignKey = $rel->getForeignKey();
        $relatedKey = $rel->getRelatedKey();

        $localIds = [];
        foreach ($models as $m) {
            $id = $m->getAttribute('id');
            if ($id !== null) {
                $localIds[] = $id;
            }
        }

        if ($localIds === []) {
            return;
        }

        $localIds = array_unique($localIds);

        $placeholders = [];
        $bindings = [];
        foreach ($localIds as $i => $lid) {
            $key = 'lid_' . $i;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $lid;
        }

        $selectCols = $columns !== ['*']
            ? implode(', ', array_map(fn ($c) => 'r.' . $c, $columns))
            : 'r.*';
        $pivotCols = 'p.' . $relatedKey . ' AS pivot_related_id, p.' . $foreignKey . ' AS pivot_foreign_id';

        $sql = sprintf(
            'SELECT %s, %s FROM %s p INNER JOIN %s r ON r.id = p.%s WHERE p.%s IN (%s)',
            $pivotCols,
            $selectCols,
            $rel->quoteIdentifier($pivotTable),
            $rel->quoteIdentifier((new $relatedClass())->getTable()),
            $rel->quoteIdentifier($relatedKey),
            $rel->quoteIdentifier($foreignKey),
            implode(', ', $placeholders)
        );

        $rows = \Siro\Core\Database::select($sql, $bindings);

        $grouped = [];
        foreach ($rows as $row) {
            $fk = (int) ($row['pivot_foreign_id'] ?? 0);
            unset($row['pivot_related_id'], $row['pivot_foreign_id']);
            $grouped[$fk][] = $row;
        }

        foreach ($models as $m) {
            $id = $m->getAttribute('id');
            $m->setRelation($relation, $grouped[(int) ($id ?? 0)] ?? []);
        }
    }

    /**
     * @param array<int, Model> $models
     * @param array<int, string> $columns
     */
    private function loadHasMany(array $models, Relations\HasMany $rel, string $relation, array $columns): void
    {
        $relatedClass = $rel->getRelatedClass();
        $foreignKey = $rel->getForeignKey();
        $localKey = $rel->getLocalKey();

        $localIds = [];
        foreach ($models as $m) {
            $id = $m->{$localKey};
            if ($id !== null) {
                $localIds[] = $id;
            }
        }

        if ($localIds === []) {
            return;
        }

        $localIds = array_unique($localIds);

        $query = (new $relatedClass())->query();
        if ($columns !== ['*']) {
            $selectCols = array_merge([$foreignKey], array_diff($columns, [$foreignKey]));
            $query->select(...$selectCols);
        }
        $rows = $query->whereIn($foreignKey, $localIds)->get();

        $grouped = [];
        foreach ($rows as $row) {
            /** @var Model $row */
            $fk = (int) ($row->{$foreignKey} ?? 0);
            $grouped[$fk][] = $row;
        }

        foreach ($models as $m) {
            $id = $m->{$localKey};
            $m->setRelation($relation, $grouped[(int) ($id ?? 0)] ?? []);
        }
    }

    /**
     * @param array<int, Model> $models
     * @param array<int, string> $columns
     */
    private function loadBelongsTo(array $models, Relations\BelongsTo $rel, string $relation, array $columns): void
    {
        $relatedClass = $rel->getRelatedClass();
        $foreignKey = $rel->getForeignKey();
        $ownerKey = $rel->getOwnerKey();

        $foreignIds = [];
        foreach ($models as $m) {
            $id = $m->{$foreignKey};
            if ($id !== null) {
                $foreignIds[] = $id;
            }
        }

        if ($foreignIds === []) {
            return;
        }

        $foreignIds = array_unique($foreignIds);

        $related = new $relatedClass();
        $ownerKeyCol = $ownerKey;
        $query = $related->query();
        if ($columns !== ['*']) {
            $selectCols = array_merge([$ownerKeyCol], array_diff($columns, [$ownerKeyCol]));
            $query->select(...$selectCols);
        }
        $rows = $query->whereIn($ownerKeyCol, $foreignIds)->get();

        $indexed = [];
        foreach ($rows as $row) {
            /** @var Model $row */
            $indexed[(int) ($row->{$ownerKeyCol} ?? 0)] = $row;
        }

        foreach ($models as $m) {
            $fk = $m->{$foreignKey};
            $m->setRelation($relation, $indexed[(int) ($fk ?? 0)] ?? null);
        }
    }
}
