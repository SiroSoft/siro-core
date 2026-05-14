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
        /** @var \Siro\Core\Model $model */
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
            if (is_numeric($id) || is_string($id)) {
                $localIds[] = $id;
            }
        }

        if ($localIds === []) {
            return;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $relatedClass();
        $query = $relatedInstance->query();
        if ($columns !== ['*']) {
            $selectCols = array_merge([$foreignKey], array_diff($columns, [$foreignKey]));
            $query->select(...$selectCols);
        }
        $rows = $query->whereIn($foreignKey, $localIds)->limit(count($localIds))->get();

        $indexed = [];
        foreach ($rows as $row) {
            /** @var Model $row */
            $idxVal = $row->{$foreignKey} ?? 0;
            $indexed[(string) $idxVal] = $row;
        }

        foreach ($models as $m) {
            $id = $m->{$localKey};
            $m->setRelation($relation, $indexed[(string) $id] ?? null);
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

        /** @var list<int|string> $localIds */
        $localIds = [];
        foreach ($models as $m) {
            $id = $m->getAttribute('id');
            if (is_numeric($id) || is_string($id)) {
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
            ? implode(', ', array_map(fn ($c) => 'r.' . $rel->quoteIdentifier(preg_replace('/^r\./', '', $c)), $columns))
            : 'r.*';
        $pivotCols = 'p.' . $relatedKey . ' AS pivot_related_id, p.' . $foreignKey . ' AS pivot_foreign_id';

        /** @var \Siro\Core\Model $relatedInstance */
        $relatedInstance = new $relatedClass();
        $sql = sprintf(
            'SELECT %s, %s FROM %s p INNER JOIN %s r ON r.id = p.%s WHERE p.%s IN (%s)',
            $pivotCols,
            $selectCols,
            $rel->quoteIdentifier($pivotTable),
            $rel->quoteIdentifier($relatedInstance->getTable()),
            $rel->quoteIdentifier($relatedKey),
            $rel->quoteIdentifier($foreignKey),
            implode(', ', $placeholders)
        );

        /** @var array<int, array<string, mixed>> $rows */
        $rows = \Siro\Core\Database::select($sql, $bindings);

        $grouped = [];
        foreach ($rows as $row) {
            $fkVal = $row['pivot_foreign_id'] ?? 0;
            $fk = (string) $fkVal;
            unset($row['pivot_related_id'], $row['pivot_foreign_id']);
            $grouped[$fk][] = $row;
        }

        foreach ($models as $m) {
            $id = $m->getAttribute('id');
            $m->setRelation($relation, $grouped[(string) $id] ?? []);
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
            if (is_numeric($id) || is_string($id)) {
                $localIds[] = $id;
            }
        }

        if ($localIds === []) {
            return;
        }

        $localIds = array_unique($localIds);

        /** @var Model $relatedInstance */
        $relatedInstance = new $relatedClass();
        $query = $relatedInstance->query();
        if ($columns !== ['*']) {
            $selectCols = array_merge([$foreignKey], array_diff($columns, [$foreignKey]));
            $query->select(...$selectCols);
        }
        $rows = $query->whereIn($foreignKey, $localIds)->get();

        $grouped = [];
        foreach ($rows as $row) {
            /** @var Model $row */
            $fkVal = $row->{$foreignKey} ?? 0;
            $grouped[(string) $fkVal][] = $row;
        }

        foreach ($models as $m) {
            $id = $m->{$localKey};
            $m->setRelation($relation, $grouped[(string) $id] ?? []);
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

        /** @var list<int|string> $foreignIds */
        $foreignIds = [];
        foreach ($models as $m) {
            $id = $m->{$foreignKey};
            if (is_numeric($id) || is_string($id)) {
                $foreignIds[] = $id;
            }
        }

        if ($foreignIds === []) {
            return;
        }

        $foreignIds = array_unique($foreignIds);

        /** @var Model $related */
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
            $idxVal = $row->{$ownerKeyCol} ?? 0;
            $indexed[(string) $idxVal] = $row;
        }

        foreach ($models as $m) {
            $fk = $m->{$foreignKey};
            $m->setRelation($relation, $indexed[(string) $fk] ?? null);
        }
    }
}
