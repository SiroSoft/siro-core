<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Metrics;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Auto-collects Prometheus metrics for every request.
 *
 * Tracks: request count, response time, status codes, active requests.
 *
 * @package Siro\Core
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $method = $request->method();
        $path = $request->path();

        Metrics::counter('http_requests_total', 1, [
            'method' => $method,
            'path' => $path,
        ]);

        $start = microtime(true);

        $response = $next($request);

        $duration = (microtime(true) - $start) * 1000;
        $status = $response instanceof Response ? $response->statusCode() : 200;

        Metrics::histogram('http_response_duration_ms', $duration, [
            'method' => $method,
            'status' => (string) $status,
        ]);

        Metrics::counter('http_responses_total', 1, [
            'method' => $method,
            'status' => (string) $status,
        ]);

        return $response;
    }
}
