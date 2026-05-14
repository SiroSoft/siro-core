<?php

declare(strict_types=1);

namespace Siro\Core\Middleware;

use Siro\Core\Auth\Idempotency;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\ValidationException;

/**
 * Idempotency middleware for preventing duplicate requests.
 *
 * Only applies to POST, PUT, PATCH methods.
 * Clients must send `Idempotency-Key` header with a unique key (UUID recommended).
 *
 * If the same key is received within the TTL window, returns the stored response
 * without re-processing the request.
 *
 * Usage in routes:
 *   $router->post('/api/orders', [OrderController::class, 'store'])
 *       ->middleware(['idempotency:86400']);
 *
 * Client usage:
 *   curl -X POST /api/orders \
 *     -H "Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000" \
 *     -d '{"product_id": 1, "quantity": 2}'
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    /** Default TTL: 24 hours */
    private const DEFAULT_TTL = 86400;

    public function handle(Request $request, callable $next, int $ttl = self::DEFAULT_TTL): mixed
    {
        $method = $request->method();

        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw new ValidationException(['idempotency_key' => ['Idempotency-Key header is required for this operation.']]);
        }

        if (strlen($idempotencyKey) > 255) {
            throw new ValidationException(['idempotency_key' => ['Idempotency-Key must be 255 characters or less.']]);
        }

        $userId = 0;
        $user = $request->user();
        if (is_array($user) && isset($user['id'])) {
            /** @var string|int|float|bool|null $id */
            $id = $user['id'];
            $userId = (int) $id;
        }

        $idempotency = new Idempotency($ttl);
        $idempotency->setKey($idempotencyKey, $userId, $method);

        if ($idempotency->isDuplicate()) {
            $storedResponse = $idempotency->getStoredResponse();

            if ($storedResponse !== null) {
                /** @var array<string, mixed> $storedResponse */
                $statusCode = isset($storedResponse['_status']) && is_numeric($storedResponse['_status']) ? (int) $storedResponse['_status'] : 200;
                unset($storedResponse['_status']);

                return Response::json($storedResponse, $statusCode)
                    ->header('X-Idempotency-Replay', 'true')
                    ->header('X-Idempotency-Key', $idempotencyKey);
            }
        }

        $response = $next($request);

        if ($response instanceof Response) {
            $statusCode = $response->statusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $payload = $response->payload();
                $payload['_status'] = $statusCode;
                $idempotency->storeResponse($payload);
            }
        }

        $idempotency->clear();

        return $response;
    }
}