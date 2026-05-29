<?php

declare(strict_types=1);

namespace Siro\Core\DB;

final class RawExpression
{
    /** @deprecated Using raw SQL expressions can lead to SQL injection. Ensure the value is properly sanitized. */
    public function __construct(private readonly string $value)
    {
        trigger_error('RawExpression is deprecated and poses SQL injection risk. Use parameterized queries instead.', E_USER_DEPRECATED);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
