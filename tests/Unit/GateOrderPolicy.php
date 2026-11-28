<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

final class GateOrderPolicy
{
    /** @param array<string, mixed> $user @param array<string, mixed> $order */
    public static function view(?array $user, array $order): bool
    {
        return ($user['id'] ?? null) === ($order['user_id'] ?? null);
    }
}
