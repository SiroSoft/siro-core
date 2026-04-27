<?php

declare(strict_types=1);

namespace Siro\Core;

abstract class Resource
{
    /** @var array<string, mixed> */
    protected array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function collection(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $result[] = (new static($item))->toArray();
        }

        return $result;
    }
}
