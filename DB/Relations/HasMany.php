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
        return (new $this->relatedClass())
            ->query()
            ->where($this->foreignKey, '=', $this->localValue);
    }

    /**
     * Forward calls to the query builder.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->query()->{$method}(...$parameters);
    }
}
