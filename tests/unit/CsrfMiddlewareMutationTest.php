<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Env;
use Siro\Core\Middleware\CsrfMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Session;

/**
 * CsrfMiddleware token generation, meta/field output, request token extraction.
 */
final class CsrfMiddlewareMutationTest extends TestCase
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
        $_COOKIE = [];
        parent::tearDown();
    }

    public function testGenerateTokenLength(): void
    {
        $token = CsrfMiddleware::generateToken();
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    public function testGenerateTokenUnique(): void
    {
        $a = CsrfMiddleware::generateToken();
        $b = CsrfMiddleware::generateToken();
        $this->assertNotSame($a, $b);
    }

    public function testGetTokenGeneratesWhenMissing(): void
    {
        $token = CsrfMiddleware::getToken();
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen(CsrfMiddleware::generateToken()));
    }

    public function testGetTokenReusesExisting(): void
    {
        $session = Session::instance();
        $session->start();
        $session->set('_csrf_token', 'existingtoken123');
        $token = CsrfMiddleware::getToken();
        $this->assertSame('existingtoken123', $token);
    }

    public function testMetaTagContainsToken(): void
    {
        $meta = CsrfMiddleware::metaTag();
        $this->assertStringContainsString('<meta name="csrf-token"', $meta);
        $this->assertStringContainsString('content="', $meta);
    }

    public function testFieldContainsToken(): void
    {
        $field = CsrfMiddleware::field();
        $this->assertStringContainsString('name="_csrf_token"', $field);
        $this->assertStringContainsString('value="', $field);
    }

    public function testHeaderTokenPriority(): void
    {
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => 'headertok']);
        $ref = new \ReflectionMethod($mw, 'getTokenFromRequest');
        $this->assertSame('headertok', $ref->invoke($mw, $req));
    }

    public function testJsonContentTypeReturnsNull(): void
    {
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x', [], ['Content-Type' => 'application/json; charset=utf-8']);
        $ref = new \ReflectionMethod($mw, 'getTokenFromRequest');
        $this->assertNull($ref->invoke($mw, $req));
    }

    public function testPostTokenFallback(): void
    {
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x', [], [], ['_csrf_token' => 'posttok']);
        $ref = new \ReflectionMethod($mw, 'getTokenFromRequest');
        $this->assertSame('posttok', $ref->invoke($mw, $req));
    }

    public function testSessionFlowMatchingTokenPasses(): void
    {
        $session = \Siro\Core\Session::instance();
        $session->start();
        $token = CsrfMiddleware::generateToken();
        $session->set('_csrf_token', $token);
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => $token]);
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success();
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }

    public function testSessionFlowWrongToken(): void
    {
        $session = \Siro\Core\Session::instance();
        $session->start();
        $token = CsrfMiddleware::generateToken();
        $session->set('_csrf_token', $token);
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => 'wrongtoken']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
        $payload = $resp->payload();
        $this->assertArrayHasKey('success', $payload);
        $this->assertArrayHasKey('message', $payload);
    }

    public function testSessionFlowMissingToken(): void
    {
        $session = \Siro\Core\Session::instance();
        $session->start();
        $session->set('_csrf_token', '');
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => 'sometoken']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
        $payload = $resp->payload();
        $this->assertArrayHasKey('success', $payload);
        $this->assertArrayHasKey('message', $payload);
    }

    public function testSessionFlowRotation(): void
    {
        $session = \Siro\Core\Session::instance();
        $session->start();
        $token = CsrfMiddleware::generateToken();
        $session->set('_csrf_token', $token);
        $session->set('_csrf_rotated_at', time() - 60);
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x', [], ['X-CSRF-TOKEN' => $token]);
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success();
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }
}