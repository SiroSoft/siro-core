<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use RuntimeException;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * CSRF Protection Middleware
 * 
 * Protects against Cross-Site Request Forgery attacks.
 * Automatically skips GET, HEAD, OPTIONS requests.
 * 
 * Usage:
 * Route::post('/api/data', [Controller::class, 'store'])->middleware([CsrfMiddleware::class]);
 */
final class CsrfMiddleware
{
    private const TOKEN_LENGTH = 32;

    /**
     * Handle CSRF verification
     */
    public function handle(Request $request, callable $next): Response
    {
        // Skip CSRF check for safe methods
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Get token from request
        $token = $this->getTokenFromRequest($request);
        
        if ($token === null || $token === '') {
            return Response::json([
                'success' => false,
                'message' => 'CSRF token missing.',
            ], 419);
        }

        // Verify token
        if (!$this->verifyToken($token)) {
            return Response::json([
                'success' => false,
                'message' => 'CSRF token mismatch.',
            ], 419);
        }

        return $next($request);
    }

    /**
     * Generate a new CSRF token
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    /**
     * Get CSRF token from session or generate new one
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = self::generateToken();
        }

        return $_SESSION['_csrf_token'];
    }

    /**
     * Get CSRF meta tag for HTML forms
     */
    public static function metaTag(): string
    {
        $token = self::getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Get CSRF token field for HTML forms
     */
    public static function field(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Get token from request (header, POST data, or query string)
     */
    private function getTokenFromRequest(Request $request): ?string
    {
        // Check header first
        $headerToken = $request->header('X-CSRF-TOKEN') ?? $request->header('X-XSRF-TOKEN');
        if ($headerToken !== null && $headerToken !== '') {
            return $headerToken;
        }

        // Check POST data
        $postToken = $request->input('_csrf_token') ?? $request->input('_token');
        if ($postToken !== null && $postToken !== '') {
            return $postToken;
        }

        return null;
    }

    /**
     * Verify CSRF token
     */
    private function verifyToken(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? null;
        
        if ($sessionToken === null || $sessionToken === '') {
            return false;
        }

        // Use hash_equals to prevent timing attacks
        return hash_equals($sessionToken, $token);
    }
}
