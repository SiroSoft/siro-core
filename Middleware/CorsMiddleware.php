<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $allowedOrigins = (string) Env::get('CORS_ALLOWED_ORIGINS', '*');
        $allowedMethods = (string) Env::get('CORS_ALLOWED_METHODS', 'GET,POST,PUT,DELETE,OPTIONS');
        $allowedHeaders = (string) Env::get('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With');

        $origin = (string) $request->header('origin', '');
        $allowOrigin = $allowedOrigins === '*' ? '*' : $this->resolveOrigin($origin, $allowedOrigins);
        $allowCredentials = $allowedOrigins !== '*' && $allowOrigin !== '*';

        if ($request->method() === 'OPTIONS') {
            $response = Response::noContent();
            $this->appendHeaders($response, $allowOrigin, $allowedMethods, $allowedHeaders, $allowCredentials);
            return $response;
        }

        $result = $next($request);
        if ($result instanceof Response) {
            $this->appendHeaders($result, $allowOrigin, $allowedMethods, $allowedHeaders, $allowCredentials);
        }

        return $result;
    }

    private function appendHeaders(Response $response, string $allowOrigin, string $allowedMethods, string $allowedHeaders, bool $allowCredentials): void
    {
        if ($allowOrigin !== '') {
            $response->header('Access-Control-Allow-Origin', $allowOrigin);
        }
        $response->header('Access-Control-Allow-Methods', $allowedMethods);
        $response->header('Access-Control-Allow-Headers', $allowedHeaders);
        $response->header('Access-Control-Allow-Credentials', $allowCredentials ? 'true' : 'false');
        $response->header('Access-Control-Expose-Headers', 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, X-Siro-Trace-Id');
        $response->header('Vary', 'Origin');
    }

    private function resolveOrigin(string $origin, string $allowedOrigins): string
    {
        $origins = array_filter(array_map('trim', explode(',', $allowedOrigins)));
        if ($origin === '' || $origin === 'null') return '';
        return in_array($origin, $origins, true) ? $origin : '';
    }
}
