<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\CorsMiddleware;
use Siro\Core\Middleware\EtagMiddleware;
use Siro\Core\Middleware\IdempotencyMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Cors + Etag + Idempotency middleware branches.
 */
final class CorsEtagIdempotencyMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        \Siro\Core\Auth\Idempotency::createTable();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        parent::tearDown();
    }

    public function testCorsWildcard(): void
    {
        $mw = new CorsMiddleware();
        $req = new Request('GET', '/x', [], ['origin' => 'https://example.com']);
        $resp = $mw->handle($req, fn () => Response::success(['ok' => 1]));
        $this->assertSame(200, $resp->statusCode());
        $this->assertSame('*', $resp->getHeader('Access-Control-Allow-Origin'));
    }

    public function testCorsOptionsPreflight(): void
    {
        $mw = new CorsMiddleware();
        $req = new Request('OPTIONS', '/x', [], ['origin' => 'https://example.com']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(204, $resp->statusCode());
        $this->assertSame('*', $resp->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $resp->getHeader('Vary'));
    }

    public function testCorsSpecificOriginMatch(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com');
        $mw = new CorsMiddleware();
        $req = new Request('GET', '/x', [], ['origin' => 'https://app.example.com']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
        $this->assertSame('https://app.example.com', $resp->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('true', $resp->getHeader('Access-Control-Allow-Credentials'));
    }

    public function testCorsSpecificOriginMismatch(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=https://app.example.com');
        $mw = new CorsMiddleware();
        $req = new Request('GET', '/x', [], ['origin' => 'https://evil.com']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testCorsNullOrigin(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=https://app.example.com');
        $mw = new CorsMiddleware();
        $req = new Request('GET', '/x', [], ['origin' => 'null']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testEtagAddsHeader(): void
    {
        $mw = new EtagMiddleware();
        $req = new Request('GET', '/api/data');
        $resp = $mw->handle($req, fn () => Response::success(['data' => 'x']));
        $this->assertSame(200, $resp->statusCode());
    }

    public function testEtagNonResponse(): void
    {
        $mw = new EtagMiddleware();
        $req = new Request('GET', '/api/data');
        $result = $mw->handle($req, fn () => ['raw' => true]);
        $this->assertSame(['raw' => true], $result);
    }

    public function testIdempotencyPostWithKey(): void
    {
        $mw = new IdempotencyMiddleware();
        $req = new Request('POST', '/api/orders', [], ['Idempotency-Key' => 'key-123']);
        $called = 0;
        $mw->handle($req, function () use (&$called) {
            $called++;
            return Response::success(['order' => $called]);
        });
        $this->assertSame(1, $called);
    }

    public function testIdempotencyGetWithoutKey(): void
    {
        $mw = new IdempotencyMiddleware();
        $req = new Request('GET', '/api/orders');
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success();
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }
}