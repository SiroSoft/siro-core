<?php

declare(strict_types=1);

namespace Siro\Core\DB\Relations;

use Siro\Core\DB\ModelQueryBuilder;
use Siro\Core\Model;

class HasMany
{
    public function __construct(
        private readonly string $relatedClass,
        private readonly string $foreignKey,
        private readonly string $localKey,
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
            ->where($this->foreignKey, '=', $this->localValue);
    }

    public function getRelatedClass(): string { return $this->relatedClass; }
    public function getForeignKey(): string { return $this->foreignKey; }
    public function getLocalKey(): string { return $this->localKey; }

    /** @param array<int, mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->query()->{$method}(...$parameters);
    }
}
