<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Auth\JWT;
use Siro\Core\Logger;
use Siro\Core\Request;
use Siro\Core\Response;
use Throwable;

final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string ...$roles): mixed
    {
        $header = (string) $request->header('authorization', '');
        $matches = [];
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return Response::error('Unauthorized', 401, [
                'token' => ['Invalid or expired token'],
            ]);
        }

        $token = trim((string) $matches[1]);
        if ($token === '') {
            return Response::error('Unauthorized', 401, [
                'token' => ['Invalid or expired token'],
            ]);
        }

        try {
            $claims = JWT::decode($token);
            $userId = (int) ($claims['sub'] ?? 0);
            $tokenVersion = (int) ($claims['ver'] ?? 0);

            if ($userId <= 0) {
                return Response::error('Unauthorized', 401, [
                    'token' => ['Invalid or expired token'],
                ]);
            }

            if ($tokenVersion <= 0) {
                return Response::error('Unauthorized', 401, [
                    'token' => ['Invalid or expired token'],
                ]);
            }

            $user = null;
            $container = \Siro\Core\Container::getInstance();
            $userModel = $container->has('user.model') ? $container->make('user.model') : null;
            if ($userModel === null && class_exists(\App\Models\User::class)) {
                $userModel = new \App\Models\User();
            }
            if ($userModel !== null) {
                /** @var \Siro\Core\Model $userModel */
                $user = $userModel->find($userId);
            }

            if ($user === null || ((int) ($user->getAttribute('status') ?? 0) !== 1)) {
                return Response::error('Unauthorized', 401, [
                    'token' => ['Invalid or expired token'],
                ]);
            }

            $userData = $user->toArray();
            if ((int) ($userData['token_version'] ?? 1) !== $tokenVersion) {
                return Response::error('Unauthorized', 401, [
                    'token' => ['Invalid or expired token'],
                ]);
            }

            $request->setUser([
                'id' => (int) ($userData['id'] ?? 0),
                'name' => (string) ($userData['name'] ?? ''),
                'email' => (string) ($userData['email'] ?? ''),
                'role' => (string) ($userData['role'] ?? 'user'),
                'status' => (int) ($userData['status'] ?? 0),
                'token_version' => (int) ($userData['token_version'] ?? 1),
                'created_at' => (string) ($userData['created_at'] ?? ''),
                'claims' => $claims,
            ]);

            if ($roles !== []) {
                $userRole = (string) ($userData['role'] ?? 'user');
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
            // Log authentication failures for security monitoring
            Logger::error('Authentication failed: ' . $e->getMessage() . ' | IP: ' . $request->ip() . ' | Path: ' . $request->path());

            return Response::error('Unauthorized', 401, [
                'token' => ['Invalid or expired token'],
            ]);
        }

        return $next($request);
    }
}
