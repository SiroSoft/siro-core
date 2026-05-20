<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Content Security Policy (CSP) middleware.
 *
 * Enforces a strict CSP by default to prevent XSS and data injection.
 * Policy can be customized via CSP_POLICY env variable.
 * Uses 'strict-dynamic' for maximum security with modern browsers.
 *
 * @package Siro\Core
 */
final class CspMiddleware implements MiddlewareInterface
{
    private const DEFAULT_POLICY = "default-src 'self'; script-src 'strict-dynamic' 'nonce-{nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    private static ?string $nonce = null;

    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = bin2hex(random_bytes(16));
        }
        return self::$nonce;
    }

    public function handle(Request $request, callable $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $policy = (string) Env::get('CSP_POLICY', '');
            if ($policy === '') {
                $policy = str_replace('{nonce}', self::nonce(), self::DEFAULT_POLICY);
            }

            $response->header('Content-Security-Policy', $policy);
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('X-Frame-Options', 'DENY');
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
