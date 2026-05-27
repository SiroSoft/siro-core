<?php

declare(strict_types=1);

namespace Siro\Core\DB;

/**
 * Join clause builder for complex join conditions.
 *
 * Used when a Closure is passed to join(), leftJoin(), or rightJoin():
 *   ->leftJoin('orders', function (JoinClause $join) {
 *       $join->on('users.id', '=', 'orders.user_id');
 *       $join->where('orders.status', '=', 'active');
 *   })
 */
final class JoinClause
{
    /** @var string */
    public string $type;
    /** @var string */
    public string $table;
    /** @var list<array{first:string, operator:string, second:string, boolean:string, bindings?:array<int, string>}> */
    public array $conditions = [];

    private SqlCompiler $compiler;

    public function __construct(string $type, string $table, SqlCompiler $compiler)
    {
        $this->type = $type;
        $this->table = $table;
        $this->compiler = $compiler;
    }

    public function on(string $first, string $operator, string $second): self
    {
        $this->conditions[] = [
            'first' => trim($first),
            'operator' => $this->compiler->normalizeOperator($operator),
            'second' => trim($second),
            'boolean' => 'AND',
        ];
        return $this;
    }

    public function orOn(string $first, string $operator, string $second): self
    {
        $this->conditions[] = [
            'first' => trim($first),
            'operator' => $this->compiler->normalizeOperator($operator),
            'second' => trim($second),
            'boolean' => 'OR',
        ];
        return $this;
    }

    public function where(string $first, string $operator, string $second): self
    {
        $this->conditions[] = [
            'first' => '?',
            'operator' => $this->compiler->normalizeOperator($operator),
            'second' => '?',
            'boolean' => 'AND',
            'bindings' => [$first, $second],
        ];
        return $this;
    }

    public function compile(): string
    {
        $parts = [];
        foreach ($this->conditions as $i => $c) {
            $prefix = $i === 0 ? '' : ' ' . $c['boolean'] . ' ';
            $first = isset($c['bindings']) ? $c['first'] : $this->compiler->quoteIdentifier($c['first']);
            $second = isset($c['bindings']) ? $c['second'] : $this->compiler->quoteIdentifier($c['second']);
            $parts[] = $prefix . $first . ' ' . $c['operator'] . ' ' . $second;
        }
        return implode('', $parts);
    }
}
