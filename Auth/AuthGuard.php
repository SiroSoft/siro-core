<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

use Siro\Core\Container;
use Siro\Core\Logger;
use Siro\Core\Request;

final class AuthGuard
{
    private static ?AuthGuard $instance = null;
    /** @var array<string, mixed>|null */
    private ?array $userData = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $guard): void
    {
        self::$instance = $guard;
    }

    /** @return array<string, mixed>|null */
    public function resolve(Request $request): ?array
    {
        $container = Container::getInstance();

        if ($container->has('auth.resolver')) {
            $resolver = $container->make('auth.resolver');
            if (is_callable($resolver)) {
                $user = $resolver($request);
                if (is_array($user)) {
                    /** @var array<string, mixed> $user */
                    $this->userData = $user;
                    return $user;
                }
            }
        }

        $header = (string) $request->header('authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $claims */
            $claims = JWT::decode(trim($matches[1]));
            $provider = $this->getUserProvider();
            $sub = $claims['sub'] ?? 0;
            $userId = is_numeric($sub) ? (int) $sub : 0;
            $user = $provider !== null
                ? $provider->retrieveById($userId)
                : null;

            if ($user === null) {
                return null;
            }

            $jtiTokenVersion = is_numeric($claims['token_version'] ?? null) ? (int) $claims['token_version'] : 0;
            $userTokenVersion = is_numeric($user['token_version'] ?? null) ? (int) $user['token_version'] : 0;
            if ($jtiTokenVersion !== $userTokenVersion) {
                return null;
            }

            /** @var array<string, mixed> $user */
            $this->userData = $user;
            $this->userData['claims'] = $claims;
            return $this->userData;
        } catch (\Throwable $e) {
            Logger::error($e);
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->userData;
    }

    public function id(): ?int
    {
        $id = $this->userData['id'] ?? null;
        return is_numeric($id) ? (int) $id : null;
    }

    public function check(): bool
    {
        return $this->userData !== null;
    }

    public function guest(): bool
    {
        return $this->userData === null;
    }

    public function hasRole(string ...$roles): bool
    {
        if ($this->userData === null) return false;
        $userRoleVal = $this->userData['role'] ?? 'user';
        $userRole = is_scalar($userRoleVal) ? (string) $userRoleVal : 'user';
        foreach ($roles as $role) {
            if (strtolower($userRole) === strtolower(trim($role))) {
                return true;
            }
        }
        return false;
    }

    public function logout(): void
    {
        $this->userData = null;
    }

    private function getUserProvider(): ?UserProvider
    {
        $container = Container::getInstance();

        if ($container->has('auth.provider')) {
            $provider = $container->make('auth.provider'); return $provider instanceof UserProvider ? $provider : null;
        }

        /** @var string $modelClass */
        $modelClass = 'App\\Models\\User';
        if (class_exists($modelClass)) {
            return new ModelUserProvider($modelClass);
        }

        return null;
    }
}
