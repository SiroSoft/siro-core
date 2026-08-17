<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\ApiKeyMiddleware;
use Siro\Core\Middleware\CspMiddleware;
use Siro\Core\Middleware\JsonMiddleware;
use Siro\Core\Middleware\VersionMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Version + Csp + ApiKey + Json middleware branches.
 */
final class SmallMiddlewaresMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678');
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        \Siro\Core\Auth\ApiKey::createTable();
        \Siro\Core\Auth\Idempotency::createTable();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('JWT_SECRET');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        parent::tearDown();
    }

    private function resetVersionMiddleware(): void
    {
        $ref = new \ReflectionClass(VersionMiddleware::class);
        foreach (['versions', 'overrides', 'latestVersion'] as $prop) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            if ($prop === 'latestVersion') {
                $p->setValue(null, 1);
            } else {
                $p->setValue(null, []);
            }
        }
    }

    public function testVersionGetVersionRegistered(): void
    {
        $this->resetVersionMiddleware();
        VersionMiddleware::register(1, '/v1');
        VersionMiddleware::register(2, '/v2');
        $req = new Request('GET', '/users', [], ['accept' => 'application/vnd.siro.v2+json']);
        $this->assertSame(2, VersionMiddleware::getVersion($req));
    }

    public function testVersionGetVersionUnregisteredFallsBack(): void
    {
        $this->resetVersionMiddleware();
        VersionMiddleware::register(1, '/v1');
        $req = new Request('GET', '/users', [], ['accept' => 'application/vnd.siro.v5+json']);
        $this->assertSame(1, VersionMiddleware::getVersion($req));
    }

    public function testVersionHandleSetsVersionHeader(): void
    {
        $this->resetVersionMiddleware();
        VersionMiddleware::register(1, '/v1');
        $mw = new VersionMiddleware();
        $req = new Request('GET', '/users', [], ['accept' => 'application/vnd.siro.v1+json']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
        $this->assertSame('1', $resp->getHeader('X-API-Version'));
    }

    public function testVersionHandleWithOverride(): void
    {
        $this->resetVersionMiddleware();
        VersionMiddleware::register(1, '/v1');
        VersionMiddleware::override(1, 'GET', '/users', ['XController', 'index']);
        $mw = new VersionMiddleware();
        $req = new Request('GET', '/users', [], ['accept' => 'application/vnd.siro.v1+json']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
        $handler = $req->versionedHandler();
        $this->assertSame(['XController', 'index'], $handler);
    }

    public function testCspAddsHeaders(): void
    {
        $mw = new CspMiddleware();
        $req = new Request('GET', '/x');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
        $this->assertNotNull($resp->getHeader('Content-Security-Policy'));
        $this->assertSame('nosniff', $resp->getHeader('X-Content-Type-Options'));
    }

    public function testCspCustomPolicy(): void
    {
        putenv('CSP_POLICY=default-src none');
        $mw = new CspMiddleware();
        $req = new Request('GET', '/x');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame('default-src none', $resp->getHeader('Content-Security-Policy'));
    }

    public function testCspNonce(): void
    {
        $nonce = CspMiddleware::nonce();
        $this->assertSame(32, strlen($nonce));
        $this->assertSame($nonce, CspMiddleware::nonce());
    }

    public function testJsonMiddlewarePassesJson(): void
    {
        $mw = new JsonMiddleware();
        $req = new Request('POST', '/x', [], ['content-type' => 'application/json'], ['a' => 1]);
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success();
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }

    public function testJsonMiddlewareNonJsonBody(): void
    {
        $mw = new JsonMiddleware();
        $req = new Request('POST', '/x');
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success();
        });
        $this->assertTrue($called);
    }

    public function testApiKeyMissingReturns401(): void
    {
        $mw = new ApiKeyMiddleware();
        $req = new Request('GET', '/api/keys');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertContains($resp->statusCode(), [401, 403]);
    }

    public function testApiKeyInvalidKey(): void
    {
        $mw = new ApiKeyMiddleware();
        $req = new Request('GET', '/api/keys', [], ['X-API-Key' => 'wrong']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertContains($resp->statusCode(), [401, 403]);
    }
}