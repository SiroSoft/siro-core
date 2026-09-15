<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Env;

final class JsonMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $method = $request->method();

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = strtolower(strval($request->header('content-type', '')));
            // File uploads are never JSON — let them through unless strict mode.
            // (Before: every multipart upload got 415.)
            if (str_contains($contentType, 'multipart/form-data')
                && !in_array(strtolower(strval(Env::get('SIRO_JSON_STRICT', ''))), ['1', 'true'], true)) {
                return $next($request);
            }
            if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
                return Response::error('Content-Type must be application/json', 415);
            }

            // Body already parsed by Request::fromGlobals(); no need to re-encode/re-decode.
        }

        return $next($request);
    }
}
