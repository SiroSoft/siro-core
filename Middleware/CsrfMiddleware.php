<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Session;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const TOKEN_LENGTH = 32;

    public function handle(Request $request, callable $next): mixed
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // API/SPA: validate origin/referer header
        $origin = $request->header('origin', '');
        $referer = $request->header('referer', '');
        $hasSession = false;
        /** @var \Siro\Core\Session|null $session */
        $session = null;

        try {
            $session = Session::instance();
            $session->start();
            $hasSession = $session->isStarted();
        } catch (\Throwable) {
            $hasSession = false;
        }

        if (!$hasSession) {
            // Double-submit cookie pattern for stateless API
            $cookieToken = isset($_COOKIE['csrf_token']) && is_string($_COOKIE['csrf_token']) ? $_COOKIE['csrf_token'] : '';
            $headerToken = (string) ($request->header('X-CSRF-TOKEN', '') ?: $request->header('X-XSRF-TOKEN', ''));
            if ($cookieToken === '' || $headerToken === '') {
                return Response::json([
                    'success' => false,
                    'message' => 'CSRF token missing.',
                ], 419);
            }
            if (!hash_equals($cookieToken, $headerToken)) {
                return Response::json([
                    'success' => false,
                    'message' => 'CSRF token mismatch.',
                ], 419);
            }
            return $next($request);
        }

        // Session-based CSRF for web apps
        $token = $this->getTokenFromRequest($request);

        if ($token === null || $token === '') {
            return Response::json([
                'success' => false,
                'message' => 'CSRF token missing.',
            ], 419);
        }

        if (!$this->verifyToken($token)) {
            return Response::json([
                'success' => false,
                'message' => 'CSRF token mismatch.',
            ], 419);
        }

        // Rotate token after successful validation to prevent reuse
        $newToken = self::generateToken();
        if ($session !== null) {
            $session->set('_csrf_token', $newToken);
        }

        return $next($request);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    public static function getToken(): string
    {
        $session = Session::instance();
        $session->start();

        $token = $session->get('_csrf_token');
        if (!is_string($token) || $token === '') {
            $token = self::generateToken();
            $session->set('_csrf_token', $token);
        }

        return $token;
    }

    public static function metaTag(): string
    {
        $token = self::getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function field(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    private function getTokenFromRequest(Request $request): ?string
    {
        $headerToken = $request->header('X-CSRF-TOKEN') ?? $request->header('X-XSRF-TOKEN');
        if (is_string($headerToken) && $headerToken !== '') {
            return $headerToken;
        }

        $postToken = $request->input('_csrf_token') ?? $request->input('_token');
        if (is_string($postToken) && $postToken !== '') {
            return $postToken;
        }

        return null;
    }

    private function verifyToken(string $token): bool
    {
        $session = Session::instance();
        $session->start();

        $sessionToken = $session->get('_csrf_token');
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }
}
