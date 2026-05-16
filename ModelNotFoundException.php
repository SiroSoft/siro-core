<?php

declare(strict_types=1);

namespace Siro\Core;

class ModelNotFoundException extends \RuntimeException
{
    public readonly string $modelClass;
    public readonly int|string $id;

    public function __construct(string $model, int|string $id)
    {
        $this->modelClass = $model;
        $this->id = $id;
        parent::__construct('Resource not found');
    }
}


