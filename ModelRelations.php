<?php

declare(strict_types=1);

namespace Siro\Core;

use Siro\Core\DB\Relations\BelongsTo;
use Siro\Core\DB\Relations\BelongsToMany;
use Siro\Core\DB\Relations\HasMany;
use Siro\Core\DB\Relations\HasOne;

trait ModelRelations
{
    abstract public function getTable(): string;
    abstract public function getAttribute(string $key): mixed;
    /**
     * Define a one-to-many relationship.
     */
    protected function hasMany(string $relatedClass, string $foreignKey = '', string $localKey = 'id'): HasMany
    {
        $related = new $relatedClass();

        if ($foreignKey === '') {
            $foreignKey = $this->getForeignKeyName($relatedClass);
        }

        return new HasMany(
            $relatedClass,
            $foreignKey,
            $localKey,
            $this->getAttribute($localKey) ?? 0,
        );
    }

    /**
     * Define a one-to-one relationship.
     */
    protected function hasOne(string $relatedClass, string $foreignKey = '', string $localKey = 'id'): HasOne
    {
        $related = new $relatedClass();

        if ($foreignKey === '') {
            $foreignKey = $this->getForeignKeyName($relatedClass);
        }

        return new HasOne(
            $relatedClass,
            $foreignKey,
            $localKey,
            $this->getAttribute($localKey) ?? 0,
        );
    }

    /**
     * Define a many-to-many relationship.
     */
    protected function belongsToMany(
        string $relatedClass,
        string $pivotTable = '',
        string $foreignKey = '',
        string $relatedKey = 'id'
    ): BelongsToMany {
        /** @var Model $related */
        $related = new $relatedClass();

        if ($pivotTable === '') {
            $tables = [$this->getTable(), $related->getTable()];
            sort($tables);
            $pivotTable = $tables[0] . '_' . $tables[1];
        }

        if ($foreignKey === '') {
            $foreignKey = $this->getForeignKeyName(static::class);
        }

        return new BelongsToMany(
            $relatedClass,
            $pivotTable,
            $foreignKey,
            $relatedKey,
            'id',
            $this->getAttribute('id') ?? 0,
        );
    }

    /**
     * Define an inverse one-to-many relationship (belongs to).
     */
    protected function belongsTo(string $relatedClass, string $foreignKey = '', string $ownerKey = 'id'): BelongsTo
    {
        if ($foreignKey === '') {
            $foreignKey = $this->getForeignKeyName(static::class);
        }

        return new BelongsTo(
            $relatedClass,
            $foreignKey,
            $ownerKey,
            $this->getAttribute($foreignKey) ?? 0,
        );
    }

    /**
     * Get the default foreign key name for a model.
     */
    private function getForeignKeyName(string $modelClass): string
    {
        $shortName = basename(str_replace('\\', '/', $modelClass));
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName)) . '_id';
    }
}
