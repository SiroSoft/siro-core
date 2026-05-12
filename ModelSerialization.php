<?php

declare(strict_types=1);

namespace Siro\Core;

trait ModelSerialization
{
    /**
     * Convert model to array (excluding hidden fields).
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
     * Cast an attribute to a native PHP type.
     */
    private function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array' => is_array($value) ? $value : json_decode((string) $value, true),
            'object' => is_object($value) ? $value : json_decode((string) $value),
            'datetime' => $value instanceof \DateTimeImmutable || $value instanceof \DateTime ? $value : new \DateTime((string) $value),
            default => $value,
        };
    }
}
