<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use RuntimeException;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Throttle Middleware - Rate limiting per route
 * 
 * Usage in routes:
 * Route::post('/login', [AuthController::class, 'login'])->throttle(5, 1); // 5 requests per minute
 * Route::post('/api/data', [DataController::class, 'store'])->throttle(60, 60); // 60 requests per hour
 */
final class ThrottleMiddleware
{
    private string $storagePath;

    public function __construct(?string $basePath = null)
    {
        $this->storagePath = ($basePath ?? getcwd()) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';
        
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }
    }

    /**
     * Handle rate limiting
     * 
     * @param int $maxAttempts Maximum number of attempts
     * @param int $decayMinutes Decay period in minutes
     */
    public function handle(Request $request, callable $next, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $key = $this->getRateLimitKey($request);
        $current = $this->getAttempts($key);
        $expiresAt = $this->getExpiresAt($key);

        // Check if limit exceeded
        if ($current >= $maxAttempts && $expiresAt > time()) {
            $retryAfter = $expiresAt - time();
            
            return Response::json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
                'meta' => [
                    'retry_after' => $retryAfter,
                    'limit' => $maxAttempts,
                    'remaining' => 0,
                ],
            ], 429)
            ->header('Retry-After', (string) $retryAfter)
            ->header('X-RateLimit-Limit', (string) $maxAttempts)
            ->header('X-RateLimit-Remaining', '0')
            ->header('X-RateLimit-Reset', (string) $expiresAt);
        }

        // Increment attempts
        $this->incrementAttempts($key, $decayMinutes);

        // Process request
        $response = $next($request);

        // Add rate limit headers to response
        $remaining = max(0, $maxAttempts - $this->getAttempts($key));
        $response->header('X-RateLimit-Limit', (string) $maxAttempts);
        $response->header('X-RateLimit-Remaining', (string) $remaining);
        $response->header('X-RateLimit-Reset', (string) $this->getExpiresAt($key));

        return $response;
    }

    /**
     * Get rate limit key based on IP and route
     */
    private function getRateLimitKey(Request $request): string
    {
        $ip = $request->ip() ?? 'unknown';
        $path = $request->path() ?? 'unknown';
        return md5($ip . ':' . $path);
    }

    /**
     * Get current attempt count
     */
    private function getAttempts(string $key): int
    {
        $file = $this->getStorageFile($key);
        
        if (!is_file($file)) {
            return 0;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return 0;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['attempts'])) {
            return 0;
        }

        // Clean up expired data
        if (isset($data['expires_at']) && $data['expires_at'] <= time()) {
            $this->removeFile($file);
            return 0;
        }

        return (int) $data['attempts'];
    }

    /**
     * Get expiration timestamp
     */
    private function getExpiresAt(string $key): int
    {
        $file = $this->getStorageFile($key);
        
        if (!is_file($file)) {
            return 0;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return 0;
        }

        $data = json_decode($content, true);
        return isset($data['expires_at']) ? (int) $data['expires_at'] : 0;
    }

    /**
     * Increment attempt count
     */
    private function incrementAttempts(string $key, int $decayMinutes): void
    {
        $file = $this->getStorageFile($key);
        $now = time();
        $expiresAt = $now + ($decayMinutes * 60);

        $data = [
            'attempts' => 1,
            'expires_at' => $expiresAt,
        ];

        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $existing = json_decode($content, true);
                if (is_array($existing) && isset($existing['expires_at']) && $existing['expires_at'] > $now) {
                    $data['attempts'] = ($existing['attempts'] ?? 0) + 1;
                    $data['expires_at'] = $existing['expires_at'];
                }
            }
        }

        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get storage file path
     */
    private function getStorageFile(string $key): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . $key . '.json';
    }

    /**
     * Remove rate limit file
     */
    private function removeFile(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
