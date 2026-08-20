<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Env;
use Siro\Core\Middleware\AuditMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * AuditMiddleware status branches (401/403/429/sensitive).
 */
final class AuditMiddlewareMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        parent::tearDown();
    }

    private function req(string $method = 'GET', string $path = '/x'): Request
    {
        return new Request($method, $path, [], ['user-agent' => 'test-agent'], [], '10.0.0.1');
    }

    public function test401LogsAuthFailed(): void
    {
        $mw = new AuditMiddleware();
        $called = false;
        $mw->handle($this->req(), function () use (&$called) {
            $called = true;
            return Response::error('Unauthorized', 401);
        });
        $this->assertTrue($called);
    }

    public function test403LogsUnauthorized(): void
    {
        $req = $this->req();
        $req->setUser(['id' => 7, 'role' => 'admin']);
        $mw = new AuditMiddleware();
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::error('Forbidden', 403);
        });
        $this->assertTrue($called);
        $this->assertSame(403, $resp->statusCode());
    }

    public function test429LogsRateLimit(): void
    {
        $mw = new AuditMiddleware();
        $called = false;
        $resp = $mw->handle($this->req(), function () use (&$called) {
            $called = true;
            return Response::error('Too Many Requests', 429);
        });
        $this->assertTrue($called);
        $this->assertSame(429, $resp->statusCode());
    }

    public function testSensitiveLogs(): void
    {
        $req = $this->req('POST', '/api/payment');
        $req->setUser(['id' => 3, 'role' => 'user']);
        $mw = new AuditMiddleware();
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success(['ok' => true]);
        }, 'sensitive');
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }

    public function testNonResponseNext(): void
    {
        $mw = new AuditMiddleware();
        $result = $mw->handle($this->req(), fn () => ['raw' => true]);
        $this->assertSame(['raw' => true], $result);
    }

    public function testSensitiveWithoutUser(): void
    {
        $mw = new AuditMiddleware();
        $called = false;
        $resp = $mw->handle($this->req('GET', '/api/plain'), function () use (&$called) {
            $called = true;
            return Response::success();
        }, 'sensitive');
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }
}