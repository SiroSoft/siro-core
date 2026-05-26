<?php

declare(strict_types=1);

namespace Siro\Core\DB\Relations;

use Siro\Core\DB\ModelQueryBuilder;
use Siro\Core\Model;

final class MorphMany
{
    /**
     * @param class-string $relatedClass The child model class (e.g. Comment)
     * @param class-string $ownerClass The parent model class (e.g. Post)
     */
    public function __construct(
        private readonly string $relatedClass,
        private readonly string $ownerClass,
        private readonly string $morphName,
        private readonly int|string $localValue,
    ) {
    }

    /** @return array<int, Model> */
    public function get(): array
    {
        return $this->query()->get();
    }

    public function query(): ModelQueryBuilder
    {
        /** @var Model $instance */
        $instance = new $this->relatedClass();
        return $instance
            ->query()
            ->where($this->morphName . '_type', '=', $this->ownerClass)
            ->where($this->morphName . '_id', '=', $this->localValue);
    }

    public function getRelatedClass(): string { return $this->relatedClass; }
    public function getMorphName(): string { return $this->morphName; }

    /** @param array<string, mixed> $data */
    public function create(array $data): Model
    {
        $data[$this->morphName . '_type'] = $this->ownerClass;
        $data[$this->morphName . '_id'] = $this->localValue;
        /** @var Model $model */
        $model = new $this->relatedClass();
        $model->fill($data);
        $model->save();
        return $model;
    }

    /** @param list<mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->query()->{$method}(...$parameters);
    }
}
