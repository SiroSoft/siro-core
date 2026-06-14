<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Cache\CacheInstance;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;
use Throwable;

final class ThrottleMiddleware implements MiddlewareInterface
{
    private const FALLBACK_DISABLED = 'disabled';
    private const FALLBACK_FAIL_CLOSED = 'fail_closed';
    private const FALLBACK_FILE = 'file';

    private ?\Redis $redis = null;
    private bool $resolved = false;

    public function handle(Request $request, callable $next, int $maxRequests = 60, int $minutes = 1): mixed
    {
        $limit = max(1, $maxRequests);
        $windowMinutes = max(1, $minutes);
        $ttl = $windowMinutes * 60;

        $redis = $this->redis();
        if (!$redis instanceof \Redis) {
            return $this->handleFallback($request, $next, $limit, $windowMinutes, $ttl);
        }

        $prefix = (string) Env::get('REDIS_PREFIX', 'siro:throttle:');
        $ip = $request->ip();
        $route = rawurlencode($request->method() . ':' . self::normalizePath($request->path()));
        $key = $prefix . sprintf('rate:%s:%s', $ip, $route);

        try {
            $result = $redis->eval(
                "local current = redis.call('INCR', KEYS[1])\n"
                . "if current == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end\n"
                . "local ttl = redis.call('TTL', KEYS[1])\n"
                . "if ttl < 0 then redis.call('EXPIRE', KEYS[1], ARGV[1]); ttl = tonumber(ARGV[1]) end\n"
                . "return {current, ttl}",
                [$key, (string) $ttl],
                1
            );

            $resultArr = is_array($result) ? $result : [0, 0];
            /** @var array<int, string|int|float|bool|null> $resultArr */
            $count = (int) ($resultArr[0] ?? 0);
            $retryAfter = max(0, (int) ($resultArr[1] ?? 0));

            if ($count <= 0) {
                throw new \RuntimeException('Invalid rate limiter counter state.');
            }

            $remaining = max(0, $limit - $count);

            if ($count > $limit) {
                $response = Response::error('Too Many Requests', 429, [
                    'throttle' => [sprintf('Rate limit exceeded. Max %d requests per %d minute(s).', $limit, $windowMinutes)],
                ]);
                $response->header('X-RateLimit-Limit', (string) $limit);
                $response->header('X-RateLimit-Remaining', (string) $remaining);
                if ($retryAfter > 0) {
                    $response->header('X-RateLimit-Reset', (string) (time() + $retryAfter));
                    $response->header('Retry-After', (string) $retryAfter);
                }
                return $response;
            }

            $response = $next($request);
            if ($response instanceof Response) {
                $response->header('X-RateLimit-Limit', (string) $limit);
                $response->header('X-RateLimit-Remaining', (string) $remaining);
                if ($retryAfter > 0) {
                    $response->header('X-RateLimit-Reset', (string) (time() + $retryAfter));
                }
            }
            return $response;
        } catch (Throwable) {
            return $this->handleFallback($request, $next, $limit, $windowMinutes, $ttl);
        }
    }

    private function handleFallback(Request $request, callable $next, int $limit, int $windowMinutes, int $ttl): mixed
    {
        $strategy = strtolower((string) Env::get('THROTTLE_FALLBACK', self::FALLBACK_FILE));

        if ($strategy === self::FALLBACK_DISABLED || $strategy === 'fail_open') {
            return $next($request);
        }

        if ($strategy === self::FALLBACK_FAIL_CLOSED) {
            return Response::error('Too Many Requests', 429, [
                'throttle' => ['Rate limiter backend unavailable'],
            ]);
        }

        return $this->enforceFileFallback($request, $next, $limit, $windowMinutes, $ttl);
    }

    private function enforceFileFallback(Request $request, callable $next, int $limit, int $windowMinutes, int $ttl): mixed
    {
        $basePath = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH
            : (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH) ? SIRO_BASE_PATH : (string) getcwd());

        $ip = $request->ip();
        $route = rawurlencode($request->method() . ':' . self::normalizePath($request->path()));
        $key = sprintf('rate:%s:%s', $ip, $route);

        $storeDir = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';
        if (!is_dir($storeDir)) {
            if (!mkdir($storeDir, 0775, true) && !is_dir($storeDir)) {
                return Response::error('Too Many Requests', 429, [
                    'throttle' => ['Rate limiter storage unavailable'],
                ]);
            }
        }

        $file = $storeDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $now = time();
        $count = 0;
        $expiresAt = $now + $ttl;

        try {
            $fp = fopen($file, 'c+');
            if ($fp === false) {
                return Response::error('Too Many Requests', 429, [
                    'throttle' => ['Rate limiter fallback storage unavailable'],
                ]);
            }

            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return Response::error('Too Many Requests', 429, [
                    'throttle' => ['Rate limiter fallback lock unavailable'],
                ]);
            }

            $raw = stream_get_contents($fp);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    /** @var array<string, string|int|float|bool|null> $decoded */
                    $storedExpires = (int) ($decoded['expires_at'] ?? 0);
                    $storedCount = (int) ($decoded['count'] ?? 0);
                    if ($storedExpires > $now) {
                        $expiresAt = $storedExpires;
                        $count = $storedCount;
                    }
                }
            }

            $count++;
            $remainingTtl = max(0, $expiresAt - $now);

            $encoded = (string) json_encode([
                'count' => $count,
                'expires_at' => $expiresAt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ftruncate($fp, 0);
            rewind($fp);
            if (fwrite($fp, $encoded) === false) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return Response::error('Too Many Requests', 429, [
                    'throttle' => ['Rate limiter fallback write failed'],
                ]);
            }
            flock($fp, LOCK_UN);
            fclose($fp);

            $remaining = max(0, $limit - $count);

            if ($count > $limit) {
                $response = Response::error('Too Many Requests', 429, [
                    'throttle' => [sprintf('Rate limit exceeded. Max %d requests per %d minute(s).', $limit, $windowMinutes)],
                ]);
                $response->header('X-RateLimit-Limit', (string) $limit);
                $response->header('X-RateLimit-Remaining', (string) $remaining);
                $response->header('X-RateLimit-Reset', (string) ($now + $remainingTtl));
                if ($remainingTtl > 0) {
                    $response->header('Retry-After', (string) $remainingTtl);
                }
                return $response;
            }

            $response = $next($request);
            if ($response instanceof Response) {
                $response->header('X-RateLimit-Limit', (string) $limit);
                $response->header('X-RateLimit-Remaining', (string) $remaining);
                $response->header('X-RateLimit-Reset', (string) ($now + $remainingTtl));
            }
            return $response;
        } catch (Throwable) {
            if (isset($fp) && is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
            return Response::error('Too Many Requests', 429, [
                'throttle' => ['Rate limiter fallback processing failed'],
            ]);
        }
    }

    private function redis(): ?\Redis
    {
        if ($this->resolved) {
            return $this->redis;
        }

        $this->resolved = true;

        $redis = CacheInstance::getRedisConnection();
        if ($redis instanceof \Redis) {
            $this->redis = $redis;
        }

        return $this->redis;
    }

    /** Normalize path by replacing numeric IDs and UUIDs with placeholders */
    private static function normalizePath(string $path): string
    {
        $segments = explode('/', $path);
        foreach ($segments as &$segment) {
            if ($segment === '') {
                continue;
            }
            if (ctype_digit($segment)) {
                $segment = '{id}';
            }
        }
        return implode('/', $segments);
    }
}
