<?php

declare(strict_types=1);

/**
 * Fixture app for OAuth2 end-to-end tests (tests/integration/OAuth2E2eTest.php).
 *
 * Boots the real framework over real HTTP (`php -S`) with the G2 bridge:
 *   POST /oauth/token, POST /oauth/revoke, GET /oauth/authorize,
 *   GET  /callback (echoes code/state, fixture-only),
 *   GET  /api/partner/data    (middleware oauth:read),
 *   POST /api/partner/write   (middleware oauth:write).
 *
 * The authorize endpoint auto-approves ONLY with ?auto_approve=1 — this is a
 * test fixture, never a production pattern.
 */

$autoload = getenv('SIRO_VENDOR_AUTOLOAD');
if ($autoload === false || $autoload === '') {
    $autoload = __DIR__ . '/../../../vendor/autoload.php';
}
require $autoload;

use Siro\Core\App;
use Siro\Core\Auth\OAuth2\AuthorizationServer;
use Siro\Core\Router;

$basePath = dirname(__DIR__);
$app = new App($basePath);
$app->boot();

Router::registerMiddlewareAlias('oauth', \Siro\Core\Middleware\OAuthScopeMiddleware::class);

AuthorizationServer::createTables();

$app->loadRoutes($basePath . '/routes/api.php');
$app->run();
