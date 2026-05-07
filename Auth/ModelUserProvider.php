<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

final class ModelUserProvider implements UserProvider
{
    /** @var class-string */
    private string $modelClass;

    /** @param class-string $modelClass */
    public function __construct(string $modelClass)
    {
        /** @var class-string $modelClass */
        $this->modelClass = $modelClass;
    }

    public function retrieveById(int $id): ?array
    {
        $model = $this->modelClass;
        $user = $model::find($id);
        return $user !== null ? $user->toArray() : null;
    }

    /** @param array<string, mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?array
    {
        $model = $this->modelClass;
        $query = $model::query();

        foreach ($credentials as $key => $value) {
            if ($key === 'password') continue;
            $query = $query->where($key, $value);
        }

        $user = $query->first();
        return $user !== null ? $user->toArray() : null;
    }

    /** @param array<string, mixed> $user */
    public function validateCredentials(array $user, string $password): bool
    {
        $hash = $user['password'] ?? '';
        return password_verify($password, $hash);
    }
}
