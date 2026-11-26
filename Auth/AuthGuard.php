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

        // OAuth2 (v1.5.0): when the bridge is enabled, a league access token
        // validates here first. Core JWTs are NOT consumed by this branch
        // (source must be 'oauth2') — they fall through to the flow below,
        // including the token-version check. When OAuth2 is disabled this
        // block is skipped entirely, so existing behavior is unchanged.
        if (\Siro\Core\Auth\OAuth2\AuthorizationServer::isEnabled()) {
            try {
                $oauth = \Siro\Core\Auth\OAuth2\AuthorizationServer::validateRequest($request);
                if ($oauth !== null && $oauth['source'] === 'oauth2') {
                    $oauthUserId = $oauth['user_id'];
                    if ($oauthUserId !== null) {
                        $oauthProvider = $this->getUserProvider();
                        $oauthUser = $oauthProvider !== null
                            ? $oauthProvider->retrieveById($oauthUserId)
                            : null;
                        if ($oauthUser === null) {
                            return null;
                        }
                        /** @var array<string, mixed> $oauthUser */
                        $this->userData = $oauthUser;
                        $this->userData['oauth'] = $oauth;
                        return $this->userData;
                    }
                    // client_credentials token: no resource owner, client context only.
                    $this->userData = [
                        'id' => null,
                        'type' => 'oauth2_client',
                        'client_id' => $oauth['client_id'],
                        'scopes' => $oauth['scopes'],
                        'oauth' => $oauth,
                    ];
                    return $this->userData;
                }
            } catch (\Throwable) {
                // Fall through to the core JWT flow below.
            }
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

            $jtiTokenVersion = is_numeric($claims['ver'] ?? null) ? (int) $claims['ver'] : 0;
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
        if ($this->userData === null) {
            return false;
        }
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
            $provider = $container->make('auth.provider');
            return $provider instanceof UserProvider ? $provider : null;
        }

        /** @var string $modelClass */
        $modelClass = 'App\\Models\\User';
        if (class_exists($modelClass)) {
            return new ModelUserProvider($modelClass);
        }

        return null;
    }
}
