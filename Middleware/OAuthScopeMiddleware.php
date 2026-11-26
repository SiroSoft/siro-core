<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Auth\OAuth2\AuthorizationServer;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * OAuth2 scope enforcement middleware.
 *
 * Validates the Bearer token (league RS256 access token first, core JWT
 * fallback) and requires every listed scope. A token with the wrong scopes
 * gets 403 with error code `insufficient_scope` (RFC 6750 §3.1); a missing,
 * expired, or revoked token gets 401.
 *
 * Usage (register the alias once in app bootstrap):
 *   Router::registerMiddlewareAlias('oauth', OAuthScopeMiddleware::class);
 *   $router->get('/api/partner/data', [Controller::class, 'data'])
 *       ->middleware(['oauth:read']);
 *   $router->post('/api/partner/orders', [Controller::class, 'create'])
 *       ->middleware(['oauth:read,write']);
 *
 * On success the OAuth2 context is attached to the request:
 *   $request->user() === ['id' => ?, 'type' => 'oauth2'|'oauth2_client',
 *                          'client_id' => ?, 'scopes' => [...], 'token_id' => ?]
 */
final class OAuthScopeMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string ...$scopes): mixed
    {
        $context = AuthorizationServer::validateRequest($request);

        if ($context === null) {
            $response = Response::error('Unauthorized', 401, [
                'token' => ['Invalid, expired, or revoked token.'],
            ], 'invalid_token');
            $response->withHeaders([
                'WWW-Authenticate' => 'Bearer realm="api", error="invalid_token"',
            ]);
            return $response;
        }

        $required = array_values(array_unique(array_filter(array_map(
            static fn (string $s): string => strtolower(trim($s)),
            $scopes,
        ), static fn (string $s): bool => $s !== '')));

        $granted = array_map('strval', $context['scopes']);
        foreach ($required as $scope) {
            if (!in_array($scope, $granted, true)) {
                $response = Response::error('Insufficient scope', 403, [
                    'scope' => ['Required scope: ' . $scope . ', token scopes: ' . implode(',', $granted)],
                ], 'insufficient_scope');
                $response->withHeaders([
                    'WWW-Authenticate' => 'Bearer realm="api", error="insufficient_scope", scope="' . implode(' ', $required) . '"',
                ]);
                return $response;
            }
        }

        $request->setUser([
            'id' => $context['user_id'],
            'type' => $context['user_id'] !== null ? 'oauth2' : 'oauth2_client',
            'client_id' => $context['client_id'],
            'scopes' => $granted,
            'token_id' => $context['token_id'],
        ]);

        return $next($request);
    }
}
