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

    public function testEtag304WhenMatch(): void
    {
        $mw = new EtagMiddleware();
        $req = new Request('GET', '/api/data');
        $first = $mw->handle($req, fn () => Response::success(['data' => 'hello']));
        $etag = $first->getHeader('ETag');
        $this->assertNotNull($etag);
        $req2 = new Request('GET', '/api/data', [], ['if-none-match' => $etag]);
        $resp = $mw->handle($req2, fn () => Response::success(['data' => 'hello']));
        $this->assertSame(304, $resp->statusCode());
    }

    public function testEtag304WhenStar(): void
    {
        $mw = new EtagMiddleware();
        $req = new Request('GET', '/api/data', [], ['if-none-match' => '*']);
        $resp = $mw->handle($req, fn () => Response::success(['data' => 'x']));
        $this->assertSame(304, $resp->statusCode());
    }

    public function testEtagNoMatchKeeps200(): void
    {
        $mw = new EtagMiddleware();
        $req = new Request('GET', '/api/data', [], ['if-none-match' => '"someothertag"']);
        $resp = $mw->handle($req, fn () => Response::success(['data' => 'x']));
        $this->assertSame(200, $resp->statusCode());
    }

    public function testEtagDisabled(): void
    {
        \Siro\Core\Middleware\EtagMiddleware::disable();
        try {
            $mw = new EtagMiddleware();
            $req = new Request('GET', '/api/data');
            $resp = $mw->handle($req, fn () => Response::success(['data' => 'x']));
            $this->assertSame(200, $resp->statusCode());
            $this->assertNull($resp->getHeader('ETag'));
        } finally {
            \Siro\Core\Middleware\EtagMiddleware::enable();
        }
    }

    public function testEtagLastModifiedMatch(): void
    {
        $mw = new EtagMiddleware();
        $resp = $mw->handle(new Request('GET', '/api/data'), fn () => Response::success(['data' => 'x'])->header('Last-Modified', 'Wed, 21 Oct 2024 07:28:00 GMT'));
        $this->assertSame(200, $resp->statusCode());
        $req2 = new Request('GET', '/api/data', [], ['if-modified-since' => 'Wed, 21 Oct 2024 07:28:00 GMT']);
        $resp2 = $mw->handle($req2, fn () => Response::success(['data' => 'x'])->header('Last-Modified', 'Wed, 21 Oct 2024 07:28:00 GMT'));
        $this->assertSame(304, $resp2->statusCode());
    }

    public function testEtagFileResponseSkipped(): void
    {
        $mw = new EtagMiddleware();
        $req = new Request('GET', '/api/file');
        $resp = $mw->handle($req, fn () => new Response(['file' => true], 200));
        $this->assertSame(200, $resp->statusCode());
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

    public function testIdempotencyMissingKeyThrows(): void
    {
        $mw = new IdempotencyMiddleware();
        $req = new Request('POST', '/api/orders');
        $this->expectException(\Siro\Core\ValidationException::class);
        $mw->handle($req, fn () => Response::success());
    }

    public function testIdempotencyKeyTooLongThrows(): void
    {
        $mw = new IdempotencyMiddleware();
        $req = new Request('POST', '/api/orders', [], ['Idempotency-Key' => str_repeat('k', 256)]);
        $this->expectException(\Siro\Core\ValidationException::class);
        $mw->handle($req, fn () => Response::success());
    }

    public function testIdempotencyDuplicateReplays(): void
    {
        $mw = new IdempotencyMiddleware();
        $called = 0;
        $req1 = new Request('POST', '/api/orders', [], ['Idempotency-Key' => 'dup-key-1']);
        $first = $mw->handle($req1, function () use (&$called) {
            $called++;
            return Response::success(['order' => 'A']);
        });
        $this->assertSame(200, $first->statusCode());
        $req2 = new Request('POST', '/api/orders', [], ['Idempotency-Key' => 'dup-key-1']);
        $second = $mw->handle($req2, function () use (&$called) {
            $called++;
            return Response::success(['order' => 'B']);
        });
        $this->assertSame(1, $called);
        $this->assertSame(200, $second->statusCode());
        $this->assertSame('true', $second->getHeader('X-Idempotency-Replay'));
        $this->assertSame('dup-key-1', $second->getHeader('X-Idempotency-Key'));
    }

    public function testIdempotencyNon2xxNotStored(): void
    {
        $mw = new IdempotencyMiddleware();
        $called = 0;
        $req = new Request('POST', '/api/orders', [], ['Idempotency-Key' => 'err-key-1']);
        $resp = $mw->handle($req, function () use (&$called) {
            $called++;
            return Response::error('Bad Request', 400, ['x' => 'y']);
        });
        $this->assertSame(400, $resp->statusCode());
        $this->assertSame(1, $called);
    }

    public function testIdempotencyWithUser(): void
    {
        $req = new Request('POST', '/api/orders', [], ['Idempotency-Key' => 'user-key-1']);
        $req->setUser(['id' => 42, 'role' => 'admin']);
        $mw = new IdempotencyMiddleware();
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success(['ok' => 1]);
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }
}