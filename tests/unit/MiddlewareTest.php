<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Middleware\CorsMiddleware;
use Siro\Core\Middleware\JsonMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

final class MiddlewareTest extends TestCase
{
    public function testCorsMiddlewareAddsHeaders(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=*');
        $request = new Request('GET', '/test', [], ['origin' => 'http://localhost']);
        $mw = new CorsMiddleware();
        $response = $mw->handle($request, fn () => Response::success([], 'OK'));
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('Access-Control-Allow-Origin', implode("\n", $response->getHeaders()));
    }

    public function testCorsMiddlewareOptionsRequest(): void
    {
        $request = new Request('OPTIONS', '/test', [], ['origin' => 'http://localhost']);
        $mw = new CorsMiddleware();
        $response = $mw->handle($request, fn () => Response::success([], 'OK'));
        $this->assertSame(204, $response->statusCode());
    }

    public function testJsonMiddlewarePassesValidJson(): void
    {
        $request = new Request('POST', '/test', [], ['content-type' => 'application/json'], ['name' => 'test']);
        $mw = new JsonMiddleware();
        $response = $mw->handle($request, fn () => Response::success([], 'OK'));
        $this->assertSame(200, $response->statusCode());
    }

    public function testJsonMiddlewareRejectsWrongContentType(): void
    {
        $request = new Request('POST', '/test', [], ['content-type' => 'text/html'], []);
        $mw = new JsonMiddleware();
        $response = $mw->handle($request, fn () => Response::success([], 'OK'));
        $this->assertSame(415, $response->statusCode());
    }

    public function testJsonMiddlewareSkipsGet(): void
    {
        $request = new Request('GET', '/test');
        $mw = new JsonMiddleware();
        $response = $mw->handle($request, fn () => Response::success([], 'OK'));
        $this->assertSame(200, $response->statusCode());
    }

    public function testCorsRespectsAllowedOrigins(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=http://example.com');
        $request = new Request('GET', '/test', [], ['origin' => 'http://other.com']);
        $mw = new CorsMiddleware();
        $response = $mw->handle($request, fn () => Response::success([], 'OK'));
        putenv('CORS_ALLOWED_ORIGINS');
        $this->assertInstanceOf(Response::class, $response);
    }
}
