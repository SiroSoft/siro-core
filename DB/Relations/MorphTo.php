<?php

declare(strict_types=1);

namespace Siro\Core\DB\Relations;

use Siro\Core\Model;

final class MorphTo
{
    private ?Model $cachedResult = null;

    public function __construct(
        private readonly string $morphName,
        private readonly int|string $localValue,
        private readonly string $morphType,
    ) {
    }

    public function get(): ?Model
    {
        if ($this->cachedResult !== null) {
            return $this->cachedResult;
        }
        if ($this->morphType === '' || $this->morphType === '0') {
            return null;
        }
        if (!class_exists($this->morphType)) {
            return null;
        }
        /** @var Model $instance */
        $instance = new $this->morphType();
        $result = $instance->query()->where('id', '=', $this->localValue)->first();
        if ($result !== null) {
            $this->cachedResult = $result;
        }
        return $result;
    }

    public function getRelatedClass(): string { return $this->morphType; }
    public function getMorphName(): string { return $this->morphName; }
}
