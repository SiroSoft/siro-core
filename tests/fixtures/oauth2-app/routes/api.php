<?php

declare(strict_types=1);

use Siro\Core\Auth\OAuth2\AuthorizationServer;
use Siro\Core\Request;
use Siro\Core\Response;

/** @var \Siro\Core\Router $router */

$router->post('/oauth/token', function (Request $request): Response {
    return AuthorizationServer::handleTokenRequest($request);
});

$router->post('/oauth/revoke', function (Request $request): Response {
    return AuthorizationServer::handleRevokeRequest($request);
});

$router->get('/oauth/authorize', function (Request $request): Response {
    if ($request->query('auto_approve') !== '1') {
        return Response::error('This fixture only supports ?auto_approve=1', 400);
    }
    $authRequest = AuthorizationServer::validateAuthorizationRequest($request);
    return AuthorizationServer::completeAuthorizationRequest($authRequest, 1, true);
});

$router->get('/callback', function (Request $request): array {
    return [
        'code' => $request->query('code'),
        'state' => $request->query('state'),
    ];
});

$router->get('/api/partner/data', function (): array {
    return ['data' => 'partner-data'];
}, ['oauth:read']);

$router->post('/api/partner/write', function (): array {
    return ['data' => 'written'];
}, ['oauth:write']);
