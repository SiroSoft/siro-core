<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\AuthMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * AuthMiddleware guard branches.
 */
final class AuthMiddlewareMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678');
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
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

    public function testMissingHeader(): void
    {
        $mw = new AuthMiddleware();
        $req = new Request('GET', '/x');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testEmptyBearer(): void
    {
        $mw = new AuthMiddleware();
        $req = new Request('GET', '/x');
        $req->header('authorization', 'Bearer ');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testRefreshTokenForbidden(): void
    {
        $token = JWT::encodeRefresh(1, 1);
        $mw = new AuthMiddleware();
        $req = new Request('GET', '/x');
        $req->header('authorization', 'Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertContains($resp->statusCode(), [401, 403]);
    }

    public function testBadToken(): void
    {
        $mw = new AuthMiddleware();
        $req = new Request('GET', '/x');
        $req->header('authorization', 'Bearer not.a.token');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertContains($resp->statusCode(), [401, 403]);
    }

    public function testUserNotFound(): void
    {
        $token = JWT::encodeAccess(9999, 1);
        $mw = new AuthMiddleware();
        $req = new Request('GET', '/x');
        $req->header('authorization', 'Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertContains($resp->statusCode(), [401, 403]);
    }
}
