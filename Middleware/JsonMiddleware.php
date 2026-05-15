<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Request;
use Siro\Core\Response;

final class JsonMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $method = $request->method();

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = $request->header('content-type', '');
            if ($contentType !== '' && !str_contains(strtolower(strval($contentType)), 'application/json')) {
                return Response::error('Content-Type must be application/json', 415);
            }

            // Read body from the already-parsed Request object
            // php://input cannot be read twice, Request caches it internally
            $body = $request->body();
            if ($body !== []) {
                $rawBody = json_encode($body);
                if ($rawBody === false || json_decode($rawBody, true) === null) {
                    return Response::error('Invalid JSON format', 400);
                }
            }
        }

        return $next($request);
    }
}
