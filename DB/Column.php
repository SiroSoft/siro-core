<?php

declare(strict_types=1);

namespace Siro\Core\DB;

/**
 * Column definition for Schema Builder Blueprint.
 *
 * Returned by Blueprint methods like $table->string('name').
 * Provides chainable modifiers: nullable(), default(), useCurrent(), unique().
 *
 * @package Siro\Core\DB
 */
final class Column
{
    public string $type;
    public string $name;
    /** @var array<string, mixed> */
    public array $params;
    public ?bool $nullable = null;
    public mixed $defaultValue = null;
    public bool $useCurrent = false;
    public bool $unique_ = false;
    public ?string $afterColumn = null;
    /** @var list<string> */
    public array $allowedValues = [];
    private ?Blueprint $blueprint;

    /** @param array<string, mixed> $params */
    public function __construct(string $type, string $name, array $params = [], ?Blueprint $blueprint = null)
    {
        $this->type = $type;
        $this->name = $name;
        $this->params = $params;
        $this->allowedValues = isset($params['allowedValues']) && is_array($params['allowedValues'])
            ? array_values(array_filter($params['allowedValues'], static fn(mixed $v): bool => is_string($v)))
            : [];
        $this->blueprint = $blueprint;
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->defaultValue = $value;
        return $this;
    }

    public function useCurrent(): self
    {
        $this->useCurrent = true;
        return $this;
    }

    public function unique(): self
    {
        $this->unique_ = true;
        if ($this->blueprint !== null) {
            $this->blueprint->unique($this->name);
        }
        return $this;
    }

    /**
     * Specify column placement (MySQL only): AFTER `column_name`.
     * Ignored on SQLite/PostgreSQL which don't support column ordering.
     */
    public function after(string $column): self
    {
        $this->afterColumn = $column;
        return $this;
    }

    /**
     * Set allowed values for ENUM type.
     *
     * @param list<string> $values
     */
    public function allowedValues(array $values): self
    {
        $this->allowedValues = $values;
        return $this;
    }
}
