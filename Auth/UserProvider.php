<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

interface UserProvider
{
    /** @return array<string, mixed>|null */
    public function retrieveById(int $id): ?array;

    /** @param array<string, mixed> $credentials */
    /** @return array<string, mixed>|null */
    public function retrieveByCredentials(array $credentials): ?array;

    /** @param array<string, mixed> $user */
    public function validateCredentials(array $user, string $password): bool;
}
