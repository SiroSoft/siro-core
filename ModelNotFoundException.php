<?php

declare(strict_types=1);

namespace Siro\Core;

class ModelNotFoundException extends \RuntimeException
{
    public function __construct(string $model, int|string $id)
    {
        parent::__construct(sprintf('%s not found with id %s', $model, (string) $id));
    }
}
