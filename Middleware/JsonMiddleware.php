<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Request;
use Siro\Core\Response;

final class JsonMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $method = $request->method();

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = $request->header('content-type', '');
            if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
                return Response::error('Content-Type must be application/json', 415);
            }

            $rawBody = file_get_contents('php://input') ?: '';
            if ($rawBody !== '') {
                json_decode($rawBody);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return Response::error('Invalid JSON format: ' . json_last_error_msg(), 400);
                }
            }
        }

        return $next($request);
    }
}
