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

            // Body already parsed by Request::fromGlobals(); no need to re-encode/re-decode.
        }

        return $next($request);
    }
}
