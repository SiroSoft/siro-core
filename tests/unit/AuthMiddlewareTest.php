<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Auth\JWT;
use Siro\Core\Middleware\AuthMiddleware;
use Siro\Core\Model;

/**
 * AuthMiddleware tests — 401/403 branches and successful auth.
 */
final class AuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('JWT_SECRET=test_jwt_secret_for_middleware_tests_32chars!!');
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT,
            role TEXT DEFAULT "user",
            status INTEGER DEFAULT 1,
            token_version INTEGER DEFAULT 1,
            created_at TEXT,
            updated_at TEXT
        )');
        AuthUser::create(['name' => 'Admin', 'email' => 'a@test.com', 'role' => 'admin', 'status' => 1]);
        putenv('AUTH_USER_MODEL=' . AuthUser::class);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        putenv('JWT_SECRET');
        putenv('AUTH_USER_MODEL');
        parent::tearDown();
    }

    private function makeRequest(?string $authHeader): Request
    {
        return new Request('GET', '/api/protected', [], $authHeader !== null ? ['Authorization' => $authHeader] : []);
    }

    public function testNoAuthHeaderReturns401(): void
    {
        $mw = new AuthMiddleware();
        $resp = $mw->handle($this->makeRequest(null), fn () => Response::success([]));
        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame(401, $resp->statusCode());
    }

    public function testEmptyTokenReturns401(): void
    {
        $mw = new AuthMiddleware();
        $resp = $mw->handle($this->makeRequest('Bearer '), fn () => Response::success([]));
        $this->assertSame(401, $resp->statusCode());
    }

    public function testNonBearerHeaderReturns401(): void
    {
        $mw = new AuthMiddleware();
        $resp = $mw->handle($this->makeRequest('Basic abc'), fn () => Response::success([]));
        $this->assertSame(401, $resp->statusCode());
    }

    public function testInvalidTokenReturns401(): void
    {
        $mw = new AuthMiddleware();
        $resp = $mw->handle($this->makeRequest('Bearer invalid.token.here'), fn () => Response::success([]));
        $this->assertSame(401, $resp->statusCode());
    }

    public function testValidAccessTokenPasses(): void
    {
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $mw = new AuthMiddleware();
        $called = false;
        $resp = $mw->handle($this->makeRequest('Bearer ' . $token), function ($req) use (&$called) {
            $called = true;
            return Response::success(['user_id' => $req->user()['id'] ?? null]);
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }

    public function testRefreshTokenRejected403(): void
    {
        JWT::reset();
        $token = JWT::encodeRefresh(1, 1);
        $mw = new AuthMiddleware();
        $resp = $mw->handle($this->makeRequest('Bearer ' . $token), fn () => Response::success([]));
        $this->assertSame(403, $resp->statusCode());
    }

    public function testRoleMismatchReturns403(): void
    {
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $mw = new AuthMiddleware();
        $resp = $mw->handle($this->makeRequest('Bearer ' . $token), fn () => Response::success([]), 'superadmin');
        $this->assertSame(403, $resp->statusCode());
    }
}

final class AuthUser extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'email', 'role', 'status', 'token_version'];
}
