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
        $this->modelClass = $modelClass;
    }

    /** @return array<string, mixed>|null */
    public function retrieveById(int $id): ?array
    {
        $model = $this->modelClass;
        /** @var \Siro\Core\Model|null $user */
        $user = $model::find($id);
        return $user !== null ? $user->toArray() : null;
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>|null
     */
    public function retrieveByCredentials(array $credentials): ?array
    {
        $model = $this->modelClass;
        /** @var \Siro\Core\DB\ModelQueryBuilder $query */
        $query = $model::query();

        foreach ($credentials as $key => $value) {
            if ($key === 'password') continue;
            $query = $query->where($key, $value);
        }

        /** @var \Siro\Core\Model|null $user */
        $user = $query->first();
        return $user !== null ? $user->toArray() : null;
    }

    /** @param array<string, string|int|float|bool|null> $user */
    public function validateCredentials(array $user, string $password): bool
    {
        $hash = (string) ($user['password'] ?? '');
        return password_verify($password, $hash);
    }
}
