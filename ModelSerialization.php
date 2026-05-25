<?php

declare(strict_types=1);

namespace Siro\Core;

trait ModelSerialization
{
    /** @var array<int, string> */
    protected array $appends = [];

    /**
     * Convert model to array (excluding hidden fields, including appends).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [];

        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden, true)) {
                $array[$key] = $this->getAttribute($key);
            }
        }

        // Append virtual attributes
        foreach ($this->appends as $attribute) {
            if (!in_array($attribute, $this->hidden, true)) {
                $array[$attribute] = $this->getAttribute($attribute);
            }
        }

        return $array;
    }

    /**
     * Serialize the model to JSON (implements JsonSerializable).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Magic getter for attributes.
     */
    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    /**
     * Magic setter for attributes.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Check if an attribute exists.
     */
    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * Get the hidden attributes.
     *
     * @return array<int, string>
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Set the hidden attributes.
     *
     * @param array<int, string> $hidden
     */
    public function setHidden(array $hidden): self
    {
        $this->hidden = $hidden;
        return $this;
    }

    /**
     * Set the attribute casts.
     *
     * @param array<string, string> $casts
     */
    public function setCasts(array $casts): self
    {
        $this->casts = $casts;
        return $this;
    }

    /**
     * Get the appended attributes.
     *
     * @return array<int, string>
     */
    public function getAppends(): array
    {
        return $this->appends;
    }

    /**
     * Set the appended attributes.
     *
     * @param array<int, string> $appends
     */
    public function setAppends(array $appends): self
    {
        $this->appends = $appends;
        return $this;
    }

    /**
     * Cast an attribute to a native PHP type.
     */
    private function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->casts[$key]) {
            'int', 'integer' => is_numeric($value) ? (int) $value : 0,
            'float', 'double', 'real' => is_numeric($value) ? (float) $value : 0.0,
            'string' => match (true) {
                is_bool($value) => $value ? '1' : '',
                is_scalar($value) => (string) $value,
                default => '',
            },
            'bool', 'boolean' => (bool) $value,
            'array' => is_array($value) ? $value : json_decode(is_string($value) ? $value : throw new \RuntimeException('Invalid cast to array'), true),
            'object' => is_object($value) ? $value : json_decode(is_string($value) ? $value : throw new \RuntimeException('Invalid cast to object')),
            'datetime', 'date' => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : new \DateTime(is_string($value) ? $value : throw new \RuntimeException('Invalid cast to datetime')),
            default => $value,
        };
    }
}
