<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Env;
use Siro\Core\Auth\JWT;
use Siro\Core\Middleware\AuthMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Tests\TestCase;

final class AuthMiddlewareDetailedTest extends TestCase
{
    private AuthMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_key_32_chars_long_for_tests_ok';
        putenv('JWT_SECRET=' . $_ENV['JWT_SECRET']);
        $this->middleware = new AuthMiddleware();
    }

    public function testBlocksMissingToken(): void
    {
        $request = new Request('GET', '/api/protected');
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(401, $response->statusCode());
    }

    public function testBlocksEmptyBearer(): void
    {
        $request = new Request('GET', '/api/protected', [], ['authorization' => 'Bearer ']);
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(401, $response->statusCode());
    }

    public function testBlocksInvalidToken(): void
    {
        $_ENV['JWT_SECRET'] = 'different_secret_key_for_testing_1234567890123456';
        putenv('JWT_SECRET=' . $_ENV['JWT_SECRET']);
        $badToken = JWT::encodeAccess(1, 1);
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_key_32_chars_long_for_tests_ok';
        putenv('JWT_SECRET=' . $_ENV['JWT_SECRET']);

        $request = new Request('GET', '/api/protected', [], ['authorization' => 'Bearer ' . $badToken]);
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(401, $response->statusCode());
    }

    public function testBlocksExpiredToken(): void
    {
        $now = time();
        $expired = JWT::encode([
            'sub' => 1, 'ver' => 1, 'iat' => $now - 7200, 'exp' => $now - 3600,
            'type' => 'access', 'jti' => bin2hex(random_bytes(16)),
        ]);
        $request = new Request('GET', '/api/protected', [], ['authorization' => 'Bearer ' . $expired]);
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(401, $response->statusCode());
    }

    public function testBlocksMalformedToken(): void
    {
        $request = new Request('GET', '/api/protected', [], ['authorization' => 'Bearer not.a.token']);
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(401, $response->statusCode());
    }

    public function testBlocksWrongAuthScheme(): void
    {
        $request = new Request('GET', '/api/protected', [], ['authorization' => 'Basic dGVzdDp0ZXN0']);
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(401, $response->statusCode());
    }

    public function testBlocksTamperedToken(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $parts[1] = str_rot13($parts[1]);
        $tampered = implode('.', $parts);

        $request = new Request('GET', '/api/protected', [], ['authorization' => 'Bearer ' . $tampered]);
        $response = $this->middleware->handle($request, fn () => Response::success());
        $this->assertSame(401, $response->statusCode());
    }

    public function testAcceptsValidToken(): void
    {
        $this->markTestSkipped('Requires database to look up user - covered by integration tests in SiroPHP.');
    }
}
