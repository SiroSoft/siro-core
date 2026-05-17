<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Metrics;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Auto-collects Prometheus metrics for every request.
 *
 * Tracks: request count, response time, status codes, memory usage.
 * Path normalization prevents cardinality explosion
 * from dynamic segments (IDs replaced with {id}).
 *
 * @package Siro\Core
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $method = $request->method();
        $path = $this->normalizePath($request->path());

        Metrics::counter('http_requests_total', 1, [
            'method' => $method,
            'path' => $path,
        ]);

        $start = microtime(true);
        $memoryBefore = memory_get_usage(true);

        $response = $next($request);

        $duration = (microtime(true) - $start) * 1000;
        $memoryPeak = memory_get_peak_usage(true) - $memoryBefore;
        $status = $response instanceof Response ? $response->statusCode() : 200;

        Metrics::histogram('http_response_duration_ms', $duration, [
            'method' => $method,
            'status' => (string) $status,
        ]);

        Metrics::histogram('http_response_memory_bytes', $memoryPeak, [
            'method' => $method,
        ]);

        Metrics::counter('http_responses_total', 1, [
            'method' => $method,
            'status' => (string) $status,
        ]);

        return $response;
    }

    /**
     * Normalize dynamic path segments to prevent label cardinality explosion.
     *
     * Replaces numeric segments with {id}, UUIDs with {uuid},
     * and common hash patterns with {hash}.
     */
    private function normalizePath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $normalized = [];

        foreach ($segments as $seg) {
            if ($seg === '') {
                continue;
            }

            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $seg)) {
                $normalized[] = '{uuid}';
            } elseif (ctype_digit($seg)) {
                $normalized[] = '{id}';
            } elseif (preg_match('/^[0-9a-f]{32}$/i', $seg)) {
                $normalized[] = '{hash}';
            } elseif (preg_match('/^[0-9a-f]{64}$/i', $seg)) {
                $normalized[] = '{hash}';
            } else {
                $normalized[] = $seg;
            }
        }

        return '/' . implode('/', $normalized);
    }
}
