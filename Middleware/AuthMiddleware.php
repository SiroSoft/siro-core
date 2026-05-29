<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Auth\JWT;
use Siro\Core\Env;
use Siro\Core\Logger;
use Siro\Core\Model;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Session;
use Throwable;

final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string ...$roles): mixed
    {
        $header = (string) $request->header('authorization', '');
        if (!str_starts_with(strtolower($header), 'bearer ')) {
            return Response::error('Unauthorized', 401, [
                'token' => ['Invalid or expired token'],
            ]);
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return Response::error('Unauthorized', 401, [
                'token' => ['Invalid or expired token'],
            ]);
        }

        try {
            /** @var array<string, mixed> $claims */
            $claims = JWT::decode($token);
            $tokenType = is_string($claims['type'] ?? null) ? $claims['type'] : '';
            if ($tokenType !== \Siro\Core\Auth\JWT::TYPE_ACCESS) {
                return Response::error('Forbidden', 403, ['token' => ['Invalid token type. Use an access token.']]);
            }
            $sub = $claims['sub'] ?? 0;
            $ver = $claims['ver'] ?? 0;
            $userId = is_numeric($sub) ? (int) $sub : 0;
            $tokenVersion = is_numeric($ver) ? (int) $ver : 0;

            if ($userId <= 0 || $tokenVersion <= 0) {
                return Response::error('Unauthorized', 401, [
                    'token' => ['Invalid or expired token'],
                ]);
            }

            $user = $this->resolveUser($request, $userId);

            if (!$user instanceof Model || (is_numeric($user->getAttribute('status')) ? (int) $user->getAttribute('status') : 0) !== 1) {
                return Response::error('Unauthorized', 401, [
                    'token' => ['Invalid or expired token'],
                ]);
            }

            /** @var array<string, mixed> $userData */
            $userData = $user->toArray();
            $tokenVer = isset($userData['token_version']) ? (is_numeric($userData['token_version']) ? (int) $userData['token_version'] : 0) : 1;
            if ($tokenVer !== $tokenVersion) {
                return Response::error('Unauthorized', 401, [
                    'token' => ['Invalid or expired token'],
                ]);
            }

            $userIdVal = isset($userData['id']) && is_numeric($userData['id']) ? (int) $userData['id'] : 0;
            $userNameVal = isset($userData['name']) && is_string($userData['name']) ? $userData['name'] : (isset($userData['name']) && is_scalar($userData['name']) ? (string) $userData['name'] : '');
            $userEmailVal = isset($userData['email']) && is_string($userData['email']) ? $userData['email'] : (isset($userData['email']) && is_scalar($userData['email']) ? (string) $userData['email'] : '');
            $userRoleVal = isset($userData['role']) && is_string($userData['role']) ? $userData['role'] : (isset($userData['role']) && is_scalar($userData['role']) ? (string) $userData['role'] : 'user');
            $userStatusVal = isset($userData['status']) && is_numeric($userData['status']) ? (int) $userData['status'] : 0;
            $userTokenVerVal = isset($userData['token_version']) && is_numeric($userData['token_version']) ? (int) $userData['token_version'] : 1;
            $userCreatedAtVal = isset($userData['created_at']) && is_string($userData['created_at']) ? $userData['created_at'] : (isset($userData['created_at']) && is_scalar($userData['created_at']) ? (string) $userData['created_at'] : '');

            $request->setUser([
                'id' => $userIdVal,
                'name' => $userNameVal,
                'email' => $userEmailVal,
                'role' => $userRoleVal,
                'status' => $userStatusVal,
                'token_version' => $userTokenVerVal,
                'created_at' => $userCreatedAtVal,
                'claims' => $claims,
            ]);

            if ($roles !== []) {
                $userRole = $userRoleVal;
                $hasRole = false;
                foreach ($roles as $role) {
                    if (strtolower($userRole) === strtolower(trim($role))) {
                        $hasRole = true;
                        break;
                    }
                }
                if (!$hasRole) {
                    return Response::error('Forbidden', 403, [
                        'role' => ['Insufficient permissions. Required: ' . implode(', ', $roles)],
                    ]);
                }
            }
        } catch (Throwable $e) {
            Logger::error('Authentication failed: ' . $e->getMessage() . ' | IP: ' . $request->ip() . ' | Path: ' . $request->path());

            return Response::error('Unauthorized', 401, [
                'token' => ['Invalid or expired token'],
            ]);
        }

        try {
            $session = Session::instance();
            if ($session->isStarted()) {
                $regenVal = $session->get('_auth_regen_at', 0);
                $lastRegen = is_numeric($regenVal) ? (int) $regenVal : 0;
                if (time() - $lastRegen > 300) {
                    $session->regenerate();
                    $session->set('_auth_regen_at', time());
                }
            }
        } catch (Throwable) {
        }

        return $next($request);
    }

    private function resolveUser(Request $request, int $userId): ?Model
    {
        $cached = $request->getAttribute('_auth_user');
        if ($cached instanceof Model) {
            $cachedId = is_numeric($cached->getAttribute('id')) ? (int) $cached->getAttribute('id') : 0;
            if ($cachedId === $userId) {
                return $cached;
            }
        }

        $modelClass = $this->resolveModelClass();
        if ($modelClass === null || !class_exists($modelClass)) {
            return null;
        }

        /** @var Model $userModel */
        $userModel = new $modelClass();
        $user = $userModel->find($userId);
        $request->setAttribute('_auth_user', $user);
        return $user;
    }

    private function resolveModelClass(): ?string
    {
        $container = \Siro\Core\Container::getInstance();
        if ($container->has('user.model')) {
            $resolved = $container->make('user.model');
            if ($resolved instanceof Model) {
                return $resolved::class;
            }
        }

        $modelClass = Env::get('AUTH_USER_MODEL', '');
        if ($modelClass !== null && $modelClass !== '' && class_exists($modelClass)) {
            return $modelClass;
        }

        $default = 'App\\Models\\User';
        if (class_exists($default)) {
            return $default;
        }
        return null;
    }
}
