<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\ServeCommand;
use Siro\Core\Env;
use Siro\Core\Middleware\AuditMiddleware;
use Siro\Core\Middleware\CsrfMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * ServeCommand guards + CsrfMiddleware branches + AuditMiddleware.
 */
final class ServeCsrfAuditMutationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        $this->basePath = sys_get_temp_dir() . '/siro_sca_' . uniqid();
        mkdir($this->basePath . '/public', 0777, true);
        $_COOKIE = [];
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        $_COOKIE = [];
        $_SERVER = [];
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testServeInvalidHost(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--host=bad host!']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testServeInvalidPortChars(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--port=12x']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testServeMissingRouter(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--host=127.0.0.1', '--port=8080']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testServePositionalPort(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['7070']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testServePositionalHost(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['example.com']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testCsrfGetPasses(): void
    {
        $mw = new CsrfMiddleware();
        $req = new Request('GET', '/x');
        $called = false;
        $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success();
        });
        $this->assertTrue($called);
    }

    public function testCsrfPostMissingToken(): void
    {
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
    }

    public function testCsrfPostMismatch(): void
    {
        $_COOKIE['csrf_token'] = 'abcdef';
        $mw = new CsrfMiddleware();
        $req = new Request('POST', '/x');
        $req->header('X-CSRF-TOKEN', 'zzzz');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(419, $resp->statusCode());
    }

    public function testAuditMiddlewareSensitive(): void
    {
        $mw = new AuditMiddleware();
        $req = new Request('GET', '/x');
        $called = false;
        $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success();
        }, 'sensitive');
        $this->assertTrue($called);
    }

    public function testAuditMiddleware401(): void
    {
        $mw = new AuditMiddleware();
        $req = new Request('GET', '/x');
        $called = false;
        $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::error('Unauthorized', 401);
        }, 'sensitive');
        $this->assertTrue($called);
    }
}
