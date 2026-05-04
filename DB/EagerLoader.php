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
        $rel = $model->{$relation}();

        if ($rel instanceof Relations\HasMany) {
            $this->loadHasMany($models, $rel, $relation, $columns);
        } elseif ($rel instanceof Relations\BelongsTo) {
            $this->loadBelongsTo($models, $rel, $relation, $columns);
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
            $fk = (int) $row->{$foreignKey};
            $grouped[$fk][] = $row;
        }

        foreach ($models as $m) {
            $id = $m->{$localKey};
            $m->setRelation($relation, $grouped[(int) $id] ?? []);
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
            $indexed[(int) $row->{$ownerKeyCol}] = $row;
        }

        foreach ($models as $m) {
            $fk = $m->{$foreignKey};
            $m->setRelation($relation, $indexed[(int) $fk] ?? null);
        }
    }
}
