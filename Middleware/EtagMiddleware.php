<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Request;
use Siro\Core\Response;

/**
 * ETag / Conditional Requests middleware.
 *
 * Generates ETag from response body, handles If-None-Match → 304.
 * Saves bandwidth for mobile apps, works with cache proxies.
 *
 * @package Siro\Core
 */
final class EtagMiddleware implements MiddlewareInterface
{
    private static bool $enabled = true;

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public function handle(Request $request, callable $next): mixed
    {
        $response = $next($request);

        if (!self::$enabled || !$response instanceof Response) {
            return $response;
        }

        $status = $response->statusCode();
        if ($status < 200 || $status >= 300 || $response->isFileResponse()) {
            return $response;
        }

        $body = (string) json_encode($response->payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . hash('sha256', $body) . '"';
        $response->header('ETag', $etag);

        $ifNoneMatch = (string) $request->header('if-none-match', '');
        if ($ifNoneMatch !== '' && ($ifNoneMatch === $etag || $ifNoneMatch === '*')) {
            return new Response([], 304);
        }

        $lastModified = $response->getHeader('Last-Modified');
        if ($lastModified !== null) {
            $ifModifiedSince = (string) $request->header('if-modified-since', '');
            if ($ifModifiedSince !== '' && $ifModifiedSince === $lastModified) {
                return new Response([], 304);
            }
        }

        return $response;
    }
}
