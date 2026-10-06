<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Middleware\ApiKeyMiddleware;
use Siro\Core\Middleware\AuthMiddleware;
use Siro\Core\Middleware\JsonMiddleware;
use Siro\Core\Middleware\ThrottleMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\Tests\TestCase;

/**
 * Router middleware parameter validation.
 *
 * String params from route definitions (e.g. 'throttle:abc,1') are validated
 * against the middleware handle() signature instead of silently coercing.
 */
final class RouterMiddlewareParamsTest extends TestCase
{
    private function dispatchWith(string $middleware): Response
    {
        $router = new Router();
        $router->get('/probe', static fn(): Response => Response::json(['ok' => true]), [$middleware]);
        return $router->dispatch(new Request('GET', '/probe'));
    }

    public function testInvalidIntParamThrowsClearError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ThrottleMiddleware.*maxRequests.*expects int.*got string/s');

        $this->dispatchWith(ThrottleMiddleware::class . ':abc,1');
    }

    public function testTooManyParamsThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/too many parameters/');

        $this->dispatchWith(ThrottleMiddleware::class . ':60,1,extra');
    }

    public function testParamsOnMiddlewareWithoutParamsThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/accepts no parameters/');

        $this->dispatchWith(JsonMiddleware::class . ':unexpected');
    }

    public function testValidVariadicParamsPass(): void
    {
        // No token -> 401 from AuthMiddleware itself, proving string params
        // passed validation and the middleware actually ran.
        $response = $this->dispatchWith(AuthMiddleware::class . ':admin,owner');

        $this->assertSame(401, $response->statusCode());
    }

    public function testApiKeyScopeParamPassesValidation(): void
    {
        // No API key header -> 401 from the middleware, not a validation error.
        $response = $this->dispatchWith(ApiKeyMiddleware::class . ':orders:read');

        $this->assertSame(401, $response->statusCode());
    }
}
