<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Bcrypt password hashing facade.
 *
 * Wraps password_hash/password_verify with consistent bcrypt
 * algorithm and testability support.
 *
 * @package Siro\Core
 */

final class Hash
{
    /** @param array<string, mixed> $options */ public static function make(string $value, array $options = []): string
    {
        $hash = @password_hash($value, PASSWORD_BCRYPT, $options);
        // @phpstan-ignore identical.alwaysFalse
        if ($hash === false) {
            throw new RuntimeException('Bcrypt hashing failed.');
        }
        return $hash;
    }

    public static function check(string $value, string $hash): bool
    {
        return password_verify($value, $hash);
    }

    /** @param array<string, mixed> $options */ public static function needsRehash(string $hash, array $options = []): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, $options);
    }

    /** @return array{algo: string, cost: int} */
    public static function info(string $hash): array
    {
        $info = password_get_info($hash);
        return [
            'algo' => $info['algoName'] ?? 'unknown',
            'cost' => $info['options']['cost'] ?? 0,
        ];
    }
}
