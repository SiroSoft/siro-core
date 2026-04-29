<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * API resource transformer.
 *
 * Converts models and arrays into structured API responses.
 * Supports auto-mapping from Model instances and field filtering.
 *
 * @package Siro\Core
 */
abstract class Resource
{
    /** @var array<string, mixed> */
    protected array $data;

    /** @param array<string, mixed>|Model $data */
    public function __construct(array|Model $data)
    {
        if ($data instanceof Model) {
            $this->data = $data->toArray();
        } else {
            $this->data = $data;
        }
    }

    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /**
     * Create a resource instance from a model or array.
     *
     * @param array<string, mixed>|Model $item
     * @param array<int, string>|null $fields
     * @return array<string, mixed>
     */
    public static function make(array|Model $item, ?array $fields = null): array
    {
        if ($fields !== null) {
            return static::makeWithFields($item, $fields);
        }

        return (new static($item))->toArray();
    }

    /**
     * @param array<int, array<string, mixed>|Model> $items
     * @return array<int, array<string, mixed>>
     */
    public static function collection(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (!is_array($item) && !$item instanceof Model) {
                continue;
            }

            $result[] = (new static($item))->toArray();
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>|Model> $items
     * @param array<int, string> $fields
     * @return array<int, array<string, mixed>>
     */
    public static function collectionOf(array $items, array $fields): array
    {
        return array_map(
            fn (array|Model $item): array => static::makeWithFields($item, $fields),
            array_filter($items, fn (mixed $item): bool => is_array($item) || $item instanceof Model)
        );
    }

    /**
     * @param array<string, mixed>|Model $item
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private static function makeWithFields(array|Model $item, array $fields): array
    {
        if ($item instanceof Model) {
            $data = $item->toArray();
        } else {
            $data = $item;
        }

        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $result[$field] = $data[$field];
            }
        }

        return $result;
    }
}
