<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Cache\CacheInstance;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;
use InvalidArgumentException;
use Throwable;

final class ThrottleMiddleware implements MiddlewareInterface
{
    private const FALLBACK_DISABLED = 'disabled';
    private const FALLBACK_FAIL_CLOSED = 'fail_closed';
    private const FALLBACK_FILE = 'file';

    /**
     * Named limiter registry: name => ['limit' => int, 'minutes' => int,
     * 'key' => string|callable]. Key strategies: 'ip' (client IP), 'user'
     * (authenticated user id), 'api_key' (API key id), or a callable
     * (Request): string returning the key part for a request.
     *
     * @var array<string, array{limit: int, minutes: int, key: string|callable}>
     */
    private static array $limiters = [];

    /** @var array<string, string> API-key plan name => limiter name. */
    private static array $planLimiters = [];

    /**
     * Define a named rate limiter, e.g. ThrottleMiddleware::define('pos', 600, 1, 'user').
     * Defining the same name again replaces the previous definition.
     *
     * @param string|callable $key Counter-key strategy ('ip', 'user', 'api_key')
     *        or a resolver returning the key part for a request.
     */
    public static function define(string $name, int $maxRequests, int $minutes = 1, string|callable $key = 'ip'): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Throttle limiter name must not be empty.');
        }
        if ($maxRequests < 1) {
            throw new InvalidArgumentException('Throttle limiter limit must be at least 1.');
        }
        if ($minutes < 1) {
            throw new InvalidArgumentException('Throttle limiter window must be at least 1 minute.');
        }
        if (is_string($key) && !in_array($key, ['ip', 'user', 'api_key'], true)) {
            throw new InvalidArgumentException(
                "Throttle limiter key strategy must be 'ip', 'user', 'api_key' or a callable, got '{$key}'."
            );
        }
        self::$limiters[$name] = ['limit' => $maxRequests, 'minutes' => $minutes, 'key' => $key];
    }

    /**
     * Map an API-key plan to a limiter: keys carrying that plan are counted
     * against the mapped limiter instead of the route's limiter.
     */
    public static function definePlan(string $plan, string $limiter): void
    {
        if ($plan === '' || $limiter === '') {
            throw new InvalidArgumentException('Throttle plan and limiter names must not be empty.');
        }
        self::$planLimiters[$plan] = $limiter;
    }

    /** Clear all defined limiters and plan mappings. Required for test isolation. */
    public static function reset(): void
    {
        self::$limiters = [];
        self::$planLimiters = [];
    }

    /** @return array<string, array{limit: int, minutes: int, key: string|callable}> */
    public static function limiters(): array
    {
        return self::$limiters;
    }

    /** @return array{limit: int, minutes: int, key: string|callable}|null */
    public static function getLimiter(string $name): ?array
    {
        return self::$limiters[$name] ?? null;
    }

    /** @return array<string, string> */
    public static function planLimiters(): array
    {
        return self::$planLimiters;
    }

    private ?\Redis $redis = null;
    private bool $resolved = false;

    /**
     * @param int|string $limiterOrMax Named limiter (string, must be defined
     *        with ::define()) or a flat max-requests limit (int, legacy).
     */
    public function handle(Request $request, callable $next, int|string $limiterOrMax = 60, int $minutes = 1): mixed
    {
        $resolved = $this->resolveLimiter($request, $limiterOrMax, $minutes);
        $limit = $resolved['limit'];
        $windowMinutes = $resolved['minutes'];
        $ttl = $windowMinutes * 60;

        $redis = $this->redis();
        if (!$redis instanceof \Redis) {
            return $this->handleFallback($request, $next, $resolved, $ttl);
        }

        $prefix = (string) Env::get('REDIS_PREFIX', 'siro:throttle:');
        $keyPart = $this->keyPart($request, $resolved['key']);
        $route = rawurlencode($request->method() . ':' . self::normalizePath($request->path()));
        $key = $prefix . sprintf('rate:%s:%s:%s', $resolved['name'], $keyPart, $route);

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
                    'throttle' => [sprintf(
                        'Rate limit exceeded. Max %d requests per %d minute(s).',
                        $limit,
                        $windowMinutes
                    )],
                ]);
                return $this->withRateHeaders($response, $limit, $remaining, time() + $retryAfter, $retryAfter);
            }

            $response = $next($request);
            if ($response instanceof Response) {
                $response = $this->withRateHeaders($response, $limit, $remaining, time() + $retryAfter, $retryAfter);
            }
            return $response;
        } catch (Throwable $e) {
            // Only the middleware's own counter-state failure may degrade to the
            // fallback backend. Any other throwable (bug or downstream error that
            // happened to surface inside the try) must not be masked as a 429.
            $isOwnStateError = $e instanceof \RuntimeException
                && $e->getMessage() === 'Invalid rate limiter counter state.';
            if (!$isOwnStateError) {
                throw $e;
            }
            return $this->handleFallback($request, $next, $resolved, $ttl);
        }
    }

    /**
     * Resolve the effective limiter for a request.
     *
     * @return array{name: string, limit: int, minutes: int, key: string|callable}
     */
    private function resolveLimiter(Request $request, int|string $limiterOrMax, int $minutes): array
    {
        if (is_int($limiterOrMax)) {
            return [
                'name' => 'default',
                'limit' => max(1, $limiterOrMax),
                'minutes' => max(1, $minutes),
                'key' => 'ip',
            ];
        }

        $limiter = self::$limiters[$limiterOrMax] ?? null;
        if ($limiter === null) {
            throw new InvalidArgumentException(
                sprintf(
                    "Throttle limiter '%s' is not defined. Define it with ThrottleMiddleware::define() first.",
                    $limiterOrMax
                )
            );
        }

        // An API-key plan (set on the request by ApiKeyMiddleware) maps to
        // its own limiter when one is registered for that plan.
        $user = $request->user();
        $planValue = is_array($user) ? ($user['plan'] ?? null) : null;
        $plan = is_string($planValue) ? $planValue : '';
        if ($plan !== '' && isset(self::$planLimiters[$plan])) {
            $mappedName = self::$planLimiters[$plan];
            $mapped = self::$limiters[$mappedName] ?? null;
            if ($mapped !== null) {
                return [
                    'name' => $mappedName,
                    'limit' => $mapped['limit'],
                    'minutes' => $mapped['minutes'],
                    'key' => $mapped['key'],
                ];
            }
        }

        return [
            'name' => $limiterOrMax,
            'limit' => $limiter['limit'],
            'minutes' => $limiter['minutes'],
            'key' => $limiter['key'],
        ];
    }

    /** @param string|callable $strategy */
    private function keyPart(Request $request, string|callable $strategy): string
    {
        $user = $request->user();
        if (is_string($strategy)) {
            $userId = is_array($user) ? ($user['id'] ?? null) : null;
            $keyId = is_array($user) ? ($user['key_id'] ?? null) : null;
            if ($strategy === 'user' && (is_string($userId) || is_int($userId))) {
                return 'user:' . (string) $userId;
            }
            if ($strategy === 'api_key') {
                if (is_string($keyId) || is_int($keyId)) {
                    return 'key:' . (string) $keyId;
                }
                if (is_string($userId) || is_int($userId)) {
                    return 'user:' . (string) $userId;
                }
            }
            return $request->ip();
        }

        // A resolver must return a string key part; anything else falls back
        // to the client IP instead of failing the request.
        $resolved = $strategy($request);
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }
        return $request->ip();
    }

    /**
     * Attach the full rate-limit header set — Limit, Remaining, Reset and
     * Retry-After — to a response, whether it is a success or a 429.
     * $resetAt is an epoch timestamp; $retryAfter is seconds until reset.
     */
    private function withRateHeaders(
        Response $response,
        int $limit,
        int $remaining,
        int $resetAt,
        int $retryAfter
    ): Response {
        $response->header('X-RateLimit-Limit', (string) $limit);
        $response->header('X-RateLimit-Remaining', (string) $remaining);
        $response->header('X-RateLimit-Reset', (string) $resetAt);
        $response->header('Retry-After', (string) max(0, $retryAfter));
        return $response;
    }

    /** @param array{name: string, limit: int, minutes: int, key: string|callable} $limiter */
    private function handleFallback(Request $request, callable $next, array $limiter, int $ttl): mixed
    {
        $limit = $limiter['limit'];
        $windowMinutes = $limiter['minutes'];
        $strategy = strtolower((string) Env::get('THROTTLE_FALLBACK', self::FALLBACK_FILE));

        if ($strategy === self::FALLBACK_DISABLED || $strategy === 'fail_open') {
            return $next($request);
        }

        if ($strategy === self::FALLBACK_FAIL_CLOSED) {
            // Fail-closed without a backend is a server-side outage, not a
            // client rate-limit breach: report 503 (not 429) so it cannot be
            // mistaken for "limit exceeded". The X-RateLimit-Backend header
            // lets operators tell the two cases apart at a glance.
            $response = Response::error('Service Unavailable', 503, [
                'throttle' => ['Rate limiter backend unavailable (fail closed)'],
            ], 'throttle_backend_unavailable');
            $response->header('X-RateLimit-Backend', 'unavailable');
            $response->header('Retry-After', '30');
            return $response;
        }

        return $this->enforceFileFallback($request, $next, $limiter, $ttl);
    }

    /** @param array{name: string, limit: int, minutes: int, key: string|callable} $limiter */
    private function enforceFileFallback(Request $request, callable $next, array $limiter, int $ttl): mixed
    {
        $limit = $limiter['limit'];
        $windowMinutes = $limiter['minutes'];
        $basePath = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH
            : (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH) ? SIRO_BASE_PATH : (string) getcwd());

        $keyPart = $this->keyPart($request, $limiter['key']);
        $route = rawurlencode($request->method() . ':' . self::normalizePath($request->path()));
        $key = sprintf('rate:%s:%s:%s', $limiter['name'], $keyPart, $route);

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
                'limiter' => $limiter['name'],
                'limit' => $limit,
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
                    'throttle' => [sprintf(
                        'Rate limit exceeded. Max %d requests per %d minute(s).',
                        $limit,
                        $windowMinutes
                    )],
                ]);
                return $this->withRateHeaders($response, $limit, $remaining, $now + $remainingTtl, $remainingTtl);
            }

            $response = $next($request);
            if ($response instanceof Response) {
                $response = $this->withRateHeaders($response, $limit, $remaining, $now + $remainingTtl, $remainingTtl);
            }
            return $response;
        } catch (Throwable $e) {
            if (isset($fp) && is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
            // Only the middleware's own counter-state failure may degrade to
            // a 429. Any other throwable (bug or downstream error surfacing
            // inside the try) must not be masked as rate limiting.
            $isOwnStateError = $e instanceof \RuntimeException
                && $e->getMessage() === 'Invalid rate limiter counter state.';
            if (!$isOwnStateError) {
                throw $e;
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
