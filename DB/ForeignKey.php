<?php

declare(strict_types=1);

namespace Siro\Core\DB;

final class ForeignKey
{
    public string $column;
    public string $references = '';
    public string $onTable = '';
    public string $onDelete = '';
    public string $onUpdate = '';

    public function __construct(string $column)
    {
        $this->column = $column;
    }

    public function references(string $column): self
    {
        $this->references = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->onTable = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }
}
