<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Middleware\CsrfMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Session;
use Siro\Core\Tests\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $middleware;
    private string $sessionDir;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['SESSION_DRIVER'] = 'file';
        putenv('SESSION_DRIVER=file');
        $this->middleware = new CsrfMiddleware();
        $this->sessionDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($this->sessionDir)) {
            mkdir($this->sessionDir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanSessionFiles();
        parent::tearDown();
    }

    private function cleanSessionFiles(): void
    {
        if (is_dir($this->sessionDir)) {
            foreach (glob($this->sessionDir . DIRECTORY_SEPARATOR . 'sess_*') as $f) {
                @unlink($f);
            }
        }
    }

    public function testSkipsGetRequests(): void
    {
        $request = new Request('GET', '/');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(200, $response->statusCode());
    }

    public function testSkipsHeadRequests(): void
    {
        $request = new Request('HEAD', '/');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(200, $response->statusCode());
    }

    public function testSkipsOptionsRequests(): void
    {
        $request = new Request('OPTIONS', '/');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(200, $response->statusCode());
    }

    public function testBlocksPostWithoutToken(): void
    {
        $request = new Request('POST', '/form');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(419, $response->statusCode());
    }

    public function testBlocksPutWithoutToken(): void
    {
        $request = new Request('PUT', '/form');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(419, $response->statusCode());
    }

    public function testBlocksDeleteWithoutToken(): void
    {
        $request = new Request('DELETE', '/form');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(419, $response->statusCode());
    }

    public function testBlocksPatchWithoutToken(): void
    {
        $request = new Request('PATCH', '/form');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(419, $response->statusCode());
    }

    public function testGeneratesValidToken(): void
    {
        $token = CsrfMiddleware::generateToken();
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function testTokenChangesBetweenCalls(): void
    {
        $t1 = CsrfMiddleware::generateToken();
        $t2 = CsrfMiddleware::generateToken();
        $this->assertNotSame($t1, $t2);
    }

    public function testMetaTagContainsToken(): void
    {
        $meta = CsrfMiddleware::metaTag();
        $this->assertStringContainsString('csrf-token', $meta);
        $this->assertStringContainsString('<meta', $meta);
    }

    public function testFieldContainsToken(): void
    {
        $field = CsrfMiddleware::field();
        $this->assertStringContainsString('_csrf_token', $field);
        $this->assertStringContainsString('<input', $field);
    }

    public function testMetaAndFieldReturnDifferentTokens(): void
    {
        $meta = CsrfMiddleware::metaTag();
        $field = CsrfMiddleware::field();
        $this->assertNotSame($meta, $field);
    }

    public function testBlocksWithWrongToken(): void
    {
        Session::instance()->start();
        Session::instance()->set('_csrf_token', 'valid_token_value_here_12345678');

        $request = new Request('POST', '/form', [], ['X-CSRF-TOKEN' => 'wrong_token_value_here_87654321']);
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(419, $response->statusCode());
    }

    public function testBlocksWithEmptyHeaderToken(): void
    {
        Session::instance()->start();
        Session::instance()->set('_csrf_token', 'some_token_value');
        $request = new Request('PUT', '/form', [], ['X-CSRF-TOKEN' => '']);
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(419, $response->statusCode());
    }
}
