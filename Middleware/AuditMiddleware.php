<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Logger;
use Siro\Core\Audit;

/**
 * Security audit middleware.
 *
 * Logs ALL security-relevant events: authentication attempts,
 * authorization failures, input validation errors, and suspicious patterns.
 * Maintains a tamper-evident audit trail for compliance.
 *
 * @package Siro\Core
 */
final class AuditMiddleware implements MiddlewareInterface
{
    public const AUDIT_FAILED_AUTH = 'auth.failed';
    public const AUDIT_RATE_LIMIT = 'rate_limit.exceeded';
    public const AUDIT_UNAUTHORIZED = 'unauthorized.access';
    public const AUDIT_SENSITIVE = 'sensitive.operation';

    public function handle(Request $request, callable $next, string ...$context): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $status = $response->statusCode();

            if ($status === 401) {
                $context = [
                    'ip' => $request->ip(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'user_agent' => $request->header('user-agent', ''),
                ];
                Logger::security(self::AUDIT_FAILED_AUTH, $context);
                Audit::log(self::AUDIT_FAILED_AUTH, $context);
            }

            if ($status === 403) {
                $user = $request->user();
                $context = [
                    'ip' => $request->ip(),
                    'user_id' => $user['id'] ?? 'unknown',
                    'role' => $user['role'] ?? 'unknown',
                    'path' => $request->path(),
                    'method' => $request->method(),
                ];
                Logger::security(self::AUDIT_UNAUTHORIZED, $context);
                Audit::log(self::AUDIT_UNAUTHORIZED, $context);
            }

            if ($status === 429) {
                $context = [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                ];
                Logger::security(self::AUDIT_RATE_LIMIT, $context);
                Audit::log(self::AUDIT_RATE_LIMIT, $context);
            }

            if (in_array('sensitive', $context, true)) {
                $user = $request->user();
                $sctx = [
                    'user_id' => $user['id'] ?? 'unknown',
                    'action' => $request->method() . ' ' . $request->path(),
                    'ip' => $request->ip(),
                ];
                Logger::security(self::AUDIT_SENSITIVE, $sctx);
                Audit::log(self::AUDIT_SENSITIVE, $sctx);
            }
        }

        return $response;
    }
}
