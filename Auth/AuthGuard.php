<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

use Siro\Core\Container;
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
            $claims = JWT::decode(trim($matches[1]));
            $provider = $this->getUserProvider();
            $user = $provider !== null
                ? $provider->retrieveById((int) ($claims['sub'] ?? 0))
                : null;

            if ($user === null) {
                return null;
            }

            $this->userData = $user;
            $this->userData['claims'] = $claims;
            return $this->userData;
        } catch (\Throwable) {
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
        return isset($this->userData['id']) ? (int) $this->userData['id'] : null;
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
        $userRole = (string) ($this->userData['role'] ?? 'user');
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
