<?php

declare(strict_types=1);

namespace Siro\Core;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<int|string, mixed> */
    protected array $items = [];

    /** @param array<int|string, mixed> $items */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public static function make(array $items = []): self
    {
        return new self($items);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function first(): mixed
    {
        return $this->items[array_key_first($this->items)] ?? null;
    }

    public function last(): mixed
    {
        return $this->items[array_key_last($this->items)] ?? null;
    }

    public function get(int|string|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->items;
        }
        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    public function set(int|string $key, mixed $value): self
    {
        $this->items[$key] = $value;
        return $this;
    }

    public function push(mixed $value): self
    {
        $this->items[] = $value;
        return $this;
    }

    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    public function unshift(mixed $value): self
    {
        array_unshift($this->items, $value);
        return $this;
    }

    public function pluck(string $column, ?string $key = null): self
    {
        $results = [];
        foreach ($this->items as $item) {
            $itemArray = is_array($item) ? $item : (array) $item;
            $value = $itemArray[$column] ?? null;
            if ($key !== null) {
                $results[($itemArray[$key] ?? null)] = $value;
            } else {
                $results[] = $value;
            }
        }
        return new self($results);
    }

    public function map(callable $callback): self
    {
        $keys = array_keys($this->items);
        $items = array_map($callback, $this->items, $keys);
        return new self(array_combine($keys, $items));
    }

    public function filter(?callable $callback = null): self
    {
        if ($callback === null) {
            return new self(array_filter($this->items));
        }
        return new self(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reject(callable $callback): self
    {
        return new self(array_filter($this->items, fn($item, $key) => !$callback($item, $key), ARRAY_FILTER_USE_BOTH));
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function each(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            $callback($item, $key);
        }
        return $this;
    }

    public function where(string $key, mixed $operator, mixed $value = null): self
    {
        return $this->filter(function ($item) use ($key, $operator, $value) {
            $itemValue = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if (func_num_args() === 2) {
                return $itemValue == $operator;
            }
            return match ($operator) {
                '=' => $itemValue == $value,
                '!=' => $itemValue != $value,
                '>' => $itemValue > $value,
                '>=' => $itemValue >= $value,
                '<' => $itemValue < $value,
                '<=' => $itemValue <= $value,
                'in' => in_array($itemValue, (array) $value, true),
                'not in' => !in_array($itemValue, (array) $value, true),
                default => $itemValue == $value,
            };
        });
    }

    public function whereIn(string $key, array $values): self
    {
        return $this->where($key, 'in', $values);
    }

    public function sort(string $column = '', string $direction = 'asc'): self
    {
        $items = $this->items;
        $desc = strtolower($direction) === 'desc';
        if ($column !== '') {
            usort($items, function ($a, $b) use ($column, $desc) {
                $aVal = is_array($a) ? ($a[$column] ?? 0) : ($a->$column ?? 0);
                $bVal = is_array($b) ? ($b[$column] ?? 0) : ($b->$column ?? 0);
                return $desc ? $bVal <=> $aVal : $aVal <=> $bVal;
            });
        } else {
            $desc ? rsort($items) : sort($items);
        }
        return new self($items);
    }

    public function sortByDesc(string $column): self
    {
        return $this->sort($column, 'desc');
    }

    public function reverse(): self
    {
        return new self(array_reverse($this->items));
    }

    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length, true));
    }

    public function chunk(int $size): self
    {
        $chunks = [];
        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new self($chunk);
        }
        return new self($chunks);
    }

    public function unique(?string $key = null): self
    {
        if ($key === null) {
            return new self(array_unique($this->items, SORT_REGULAR));
        }
        $seen = [];
        return $this->filter(function ($item) use ($key, &$seen) {
            $val = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if (in_array($val, $seen, true)) {
                return false;
            }
            $seen[] = $val;
            return true;
        });
    }

    public function collapse(): self
    {
        $results = [];
        foreach ($this->items as $item) {
            if (is_array($item)) {
                $results = array_merge($results, $item);
            }
        }
        return new self($results);
    }

    public function flatten(int $depth = INF): self
    {
        $result = [];
        foreach ($this->items as $item) {
            if (is_array($item)) {
                if ($depth === 1) {
                    $result = array_merge($result, $item);
                } else {
                    $result = array_merge($result, (new self($item))->flatten($depth - 1)->all());
                }
            } else {
                $result[] = $item;
            }
        }
        return new self($result);
    }

    public function combine(array $values): self
    {
        return new self(array_combine($this->items, $values));
    }

    public function keys(): self
    {
        return new self(array_keys($this->items));
    }

    public function values(): self
    {
        return new self(array_values($this->items));
    }

    public function merge(array|self $items): self
    {
        $items = $items instanceof self ? $items->all() : $items;
        return new self(array_merge($this->items, $items));
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->items, $options | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function implode(string $glue = ','): string
    {
        return implode($glue, $this->items);
    }

    public function sum(?string $column = null): float|int
    {
        if ($column === null) {
            return array_sum($this->items);
        }
        return array_sum(array_map(fn($item) => is_array($item) ? ($item[$column] ?? 0) : ($item->$column ?? 0), $this->items));
    }

    public function avg(?string $column = null): float|int
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($column) / $count : 0;
    }

    public function min(?string $column = null): mixed
    {
        if ($column === null) {
            return min($this->items);
        }
        return min(array_map(fn($item) => is_array($item) ? ($item[$column] ?? 0) : ($item->$column ?? 0), $this->items));
    }

    public function max(?string $column = null): mixed
    {
        if ($column === null) {
            return max($this->items);
        }
        return max(array_map(fn($item) => is_array($item) ? ($item[$column] ?? 0) : ($item->$column ?? 0), $this->items));
    }

    public function shuffle(): self
    {
        $items = $this->items;
        shuffle($items);
        return new self($items);
    }

    public function random(?int $count = null): mixed
    {
        if ($count === null) {
            return $this->items[array_rand($this->items)];
        }
        $keys = array_rand($this->items, min($count, $this->count()));
        $keys = is_array($keys) ? $keys : [$keys];
        return new self(array_intersect_key($this->items, array_flip($keys)));
    }

    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    public function dd(): void
    {
        dd($this->items);
    }

    public function dump(): self
    {
        dump($this->items);
        return $this;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
