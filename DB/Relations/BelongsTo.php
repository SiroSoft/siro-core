<?php

declare(strict_types=1);

namespace Siro\Core\DB\Relations;

use Siro\Core\DB\ModelQueryBuilder;
use Siro\Core\Model;

class BelongsTo
{
    public function __construct(
        private readonly string $relatedClass,
        private readonly string $foreignKey,
        private readonly string $ownerKey,
        private readonly int|string $foreignValue,
    ) {
    }

    public function get(): ?Model
    {
        if ($this->foreignValue === 0 || $this->foreignValue === '') {
            return null;
        }

        return $this->query()->first();
    }

    public function query(): ModelQueryBuilder
    {
        return (new $this->relatedClass())
            ->query()
            ->where($this->ownerKey, '=', $this->foreignValue);
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->query()->{$method}(...$parameters);
    }
}
