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
        $allowOrigin = $allowedOrigins === '*' ? ($origin !== '' ? $origin : '*') : $this->resolveOrigin($origin, $allowedOrigins);
        $allowCredentials = $allowedOrigins !== '*';

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
        $response->header('Access-Control-Allow-Origin', $allowOrigin);
        $response->header('Access-Control-Allow-Methods', $allowedMethods);
        $response->header('Access-Control-Allow-Headers', $allowedHeaders);
        $response->header('Access-Control-Allow-Credentials', $allowCredentials ? 'true' : 'false');
        $response->header('Vary', 'Origin');
    }

    private function resolveOrigin(string $origin, string $allowedOrigins): string
    {
        $origins = array_map('trim', explode(',', $allowedOrigins));
        return in_array($origin, $origins, true) ? $origin : '';
    }
}
