<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Gate;
use Siro\Core\Request;

/**
 * Enforce Gate abilities on a route.
 *
 * All listed abilities must pass for $request->user(). Denial throws
 * AuthorizationException (rendered as 403 forbidden by App::run()).
 * A plain string keeps routes serializable for route caching.
 *
 * Usage in routes:
 *   $router->post('/products', [ProductController::class, 'store'],
 *       ['auth', AuthorizeMiddleware::class . ':products.create']);
 *
 * @package Siro\Core\Middleware
 */
final class AuthorizeMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, string ...$abilities): mixed
    {
        foreach ($abilities as $ability) {
            Gate::authorize($ability, $request->user());
        }
        return $next($request);
    }
}
