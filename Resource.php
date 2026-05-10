<?php

declare(strict_types=1);

namespace Siro\Core;

abstract class Resource
{
    /** @var array<string, mixed> */
    protected array $data;

    /** @param array<string, mixed>|Model $data */
    public function __construct(array|Model $data)
    {
        if (is_array($data)) {
            $this->data = $data;
        } else {
            $this->data = $data->toArray();
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
            return self::makeWithFields($item, $fields);
        }

        /** @phpstan-ignore new.static */
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
            /** @phpstan-ignore new.static */
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
            fn (array|Model $item): array => self::makeWithFields($item, $fields),
            $items
        );
    }

    /**
     * @param array<string, mixed>|Model $item
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private static function makeWithFields(array|Model $item, array $fields): array
    {
        if (is_array($item)) {
            $data = $item;
        } else {
            $data = $item->toArray();
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
