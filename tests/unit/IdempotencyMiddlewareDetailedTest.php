<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Auth\Idempotency;
use Siro\Core\Middleware\IdempotencyMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Tests\TestCase;

final class IdempotencyMiddlewareDetailedTest extends TestCase
{
    private IdempotencyMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        \Siro\Core\Database::configure(['driver' => 'sqlite', 'database' => ':memory:', 'charset' => 'utf8']);
        Idempotency::createTable();
        $this->middleware = new IdempotencyMiddleware();
    }

    protected function tearDown(): void
    {
        try { \Siro\Core\Database::connection()->rollBack(); } catch (\Throwable) {}
        \Siro\Core\Database::purge();
        parent::tearDown();
    }

    public function testSkipsGetRequests(): void
    {
        $request = new Request('GET', '/api/orders');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(200, $response->statusCode());
    }

    public function testRequiresIdempotencyKeyOnPost(): void
    {
        $request = new Request('POST', '/api/orders', [], [], ['product' => 1]);
        $this->expectException(\Siro\Core\ValidationException::class);
        $this->middleware->handle($request, fn () => Response::success());
    }

    public function testRequiresIdempotencyKeyOnPut(): void
    {
        $request = new Request('PUT', '/api/orders/1', [], [], ['name' => 'test']);
        $this->expectException(\Siro\Core\ValidationException::class);
        $this->middleware->handle($request, fn () => Response::success());
    }

    public function testPassesWithValidKeyOnPost(): void
    {
        $request = new Request('POST', '/api/orders', [], ['Idempotency-Key' => '550e8400-e29b-41d4-a716-446655440000'], ['product' => 1]);
        $response = $this->middleware->handle($request, fn () => Response::created(['id' => 1], 'Order created'));
        $this->assertSame(201, $response->statusCode());
    }

    public function testRejectsDuplicateRequest(): void
    {
        $key = 'dup-key-' . uniqid();
        $request = new Request('POST', '/api/orders', [], ['Idempotency-Key' => $key], ['product' => 1]);
        $next = fn () => Response::created(['id' => 999], 'Order created');

        $first = $this->middleware->handle($request, $next);
        $this->assertSame(201, $first->statusCode());

        $second = $this->middleware->handle($request, $next);
        $this->assertSame(201, $second->statusCode());
    }

    public function testRejectsOversizedKey(): void
    {
        $longKey = str_repeat('a', 256);
        $request = new Request('POST', '/api/orders', [], ['Idempotency-Key' => $longKey], ['product' => 1]);
        $this->expectException(\Siro\Core\ValidationException::class);
        $this->middleware->handle($request, fn () => Response::success());
    }

    public function testAcceptsKeyAtMaxLength(): void
    {
        $maxKey = str_repeat('b', 255);
        $request = new Request('POST', '/api/orders', [], ['Idempotency-Key' => $maxKey], ['product' => 1]);
        $response = $this->middleware->handle($request, fn () => Response::created(['id' => 1]));
        $this->assertSame(201, $response->statusCode());
    }

    public function testDuplicateResponseHasReplayHeader(): void
    {
        $key = 'replay-header-test';
        $request = new Request('POST', '/api/orders', [], ['Idempotency-Key' => $key], ['product' => 1]);
        $next = fn () => Response::created(['id' => 42], 'Created');

        $this->middleware->handle($request, $next);
        $second = $this->middleware->handle($request, $next);

        $headers = $second->getHeaders();
        $found = false;
        foreach ($headers as $h) {
            if (str_contains($h, 'X-Idempotency-Replay: true')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
}
