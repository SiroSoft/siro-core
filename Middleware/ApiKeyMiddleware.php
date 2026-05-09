<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Auth\ApiKey;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * API Key authentication middleware for external developers.
 *
 * Validates X-Api-Key header against stored keys.
 * Optionally enforces scopes.
 *
 * Usage:
 *   $router->get('/api/external/data', [Controller::class, 'data'])
 *       ->middleware(['apikey:read']);
 *
 *   $router->post('/api/external/orders', [Controller::class, 'create'])
 *       ->middleware(['apikey:write']);
 *
 * Client sends:
 *   curl -H "X-Api-Key: abc123..." /api/external/data
 */
final class ApiKeyMiddleware
{
    public function handle(Request $request, callable $next, string $requiredScope = ''): mixed
    {
        $token = $request->header('X-Api-Key');

        if ($token === null || $token === '') {
            return Response::error('API key required', 401, ['api_key' => ['X-Api-Key header is required.']]);
        }

        $keyData = ApiKey::validate($token);

        if ($keyData === null) {
            return Response::error('Invalid or expired API key', 401, ['api_key' => ['Invalid API key.']]);
        }

        if ($requiredScope !== '') {
            $scopes = array_map('trim', explode(',', $keyData['scopes']));
            $requiredScope = strtolower(trim($requiredScope));

            $hasScope = in_array('admin', $scopes, true) || in_array($requiredScope, $scopes, true);

            if (!$hasScope) {
                return Response::error('Insufficient scope', 403, [
                    'scope' => ["Required scope: {$requiredScope}, key scopes: {$keyData['scopes']}"]
                ]);
            }
        }

        $request->setUser([
            'id' => $keyData['user_id'],
            'type' => 'api_key',
            'name' => $keyData['name'],
            'scopes' => $keyData['scopes'],
            'key_id' => $keyData['id'],
        ]);

        return $next($request);
    }
}