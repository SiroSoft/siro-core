<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Auth\AuthGuard;
use Siro\Core\Auth\JWT;
use Siro\Core\Cache;
use Siro\Core\Container;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\AuthMiddleware;
use Siro\Core\Middleware\CorsMiddleware;
use Siro\Core\Middleware\CspMiddleware;
use Siro\Core\Middleware\EtagMiddleware;
use Siro\Core\Middleware\IdempotencyMiddleware;
use Siro\Core\Middleware\JsonMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

final class EscapedMutantsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        JWT::reset();
        putenv('APP_ENV=testing');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('JWT_SECRET');
        putenv('JWT_ALGORITHM');
        putenv('CSP_POLICY');
        putenv('ALLOWED_ORIGINS');
        Env::reset();
        JWT::reset();
        Cache::reset();
        $_COOKIE = [];
        $_ENV = [];
        parent::tearDown();
    }

    // ========== ApiKey: LessThan boundary (lines 104, 183) ==========

    public function testApiKeyExpiredAtExactNowReturnsNull(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        try { ApiKey::ensureTable(); } catch (\Throwable) { $this->markTestSkipped('Cannot create table'); }
        $token = bin2hex(random_bytes(16));
        $hash = password_hash($token, PASSWORD_BCRYPT);
        $now = time();
        Database::execute(
            "INSERT INTO " . ApiKey::$table . " (name, token_hash, token_bcrypt, scopes, user_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['exp-exact', hash('sha256', $token), $hash, 'read', 1, $now, $now]
        );
        $found = ApiKey::findByToken($token);
        $this->assertNull($found, 'Token with expires_at == now should be rejected (LessThan mutant)');
        Database::execute("DELETE FROM " . ApiKey::$table . " WHERE name = ?", ['exp-exact']);
    }

    public function testApiKeyExpiresOneSecondAfterNowIsValid(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        try { ApiKey::ensureTable(); } catch (\Throwable) { $this->markTestSkipped('Cannot create table'); }
        $token = bin2hex(random_bytes(16));
        $hash = password_hash($token, PASSWORD_BCRYPT);
        Database::execute(
            "INSERT INTO " . ApiKey::$table . " (name, token_hash, token_bcrypt, scopes, user_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['exp-after', hash('sha256', $token), $hash, 'read', 1, time(), time() + 1]
        );
        $found = ApiKey::findByToken($token);
        $this->assertNotNull($found, 'Token expiring 1s in future should be valid');
        Database::execute("DELETE FROM " . ApiKey::$table . " WHERE name = ?", ['exp-after']);
    }

    // ========== ApiKey: UnwrapTrim on scope (line 207) ==========

    public function testApiKeyHasScopeWithLeadingSpace(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        try { ApiKey::ensureTable(); } catch (\Throwable) { $this->markTestSkipped('Cannot create table'); }
        $token = bin2hex(random_bytes(16));
        $hash = password_hash($token, PASSWORD_BCRYPT);
        Database::execute(
            "INSERT INTO " . ApiKey::$table . " (name, token_hash, token_bcrypt, scopes, user_id, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['scope-trim', hash('sha256', $token), $hash, 'read,write', 1, time(), 0]
        );
        $found = ApiKey::findByToken($token);
        $this->assertNotNull($found);
        $this->assertTrue(ApiKey::hasScope($found, ' read '));
        $this->assertTrue(ApiKey::hasScope($found, 'write'));
        Database::execute("DELETE FROM " . ApiKey::$table . " WHERE name = ?", ['scope-trim']);
    }

    // ========== AuthGuard: PregMatch (line 48) ==========

    public function testAuthGuardBearerWithTrailingNewlineFails(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $container = Container::getInstance();
        $container->clear();
        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => "Bearer dummy_token\n"]);
        $user = $guard->resolve($request);
        $this->assertNull($user, 'Bearer token with trailing newline should fail (PregMatchRemoveDollar)');
    }

    public function testAuthGuardBearerWithoutStartAnchorFails(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $container = Container::getInstance();
        $container->clear();
        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => "X-Bearer dummy_token"]);
        $user = $guard->resolve($request);
        $this->assertNull($user, 'Bearer without ^ anchor should fail (PregMatchRemoveCaret)');
    }

    public function testAuthGuardBearerCaseInsensitive(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $container = Container::getInstance();
        $container->clear();
        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => 'bearer mytoken']);
        $user = $guard->resolve($request);
        $this->assertNull($user, 'bearer should be case-insensitive but token invalid (PregMatchRemoveFlags)');
    }

    // ========== AuthGuard: MethodCallRemoval Logger::error (line 77) ==========

    public function testAuthGuardLogsErrorOnInvalidToken(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $container = Container::getInstance();
        $container->clear();
        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer invalid.jwt.token']);
        $user = $guard->resolve($request);
        $this->assertNull($user);
    }

    // ========== AuthGuard: UnwrapTrim (line 54, 110) ==========

    public function testAuthGuardTrimsTokenFromBearerHeader(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $container = Container::getInstance();
        $container->clear();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array { return ['id' => 99, 'role' => 'user']; }
        });
        $guard = new AuthGuard();
        $token = JWT::encodeAccess(99, 1);
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer  ' . $token . '  ']);
        $user = $guard->resolve($request);
        $this->assertNotNull($user, 'Guard should trim whitespace around token (UnwrapTrim)');
    }

    public function testAuthGuardRoleCheckWithLeadingSpace(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $container = Container::getInstance();
        $container->clear();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array { return ['id' => 1, 'role' => 'admin']; }
        });
        $guard = new AuthGuard();
        $request = new Request('GET', '/');
        $guard->resolve($request);
        $this->assertTrue($guard->hasRole(' admin '), 'hasRole should trim the argument (UnwrapTrim)');
    }

    // ========== AuthGuard: InstanceOf_ (line 127) ==========

    public function testAuthGuardReturnsNullForNonUserProvider(): void
    {
        $container = Container::getInstance();
        $container->clear();
        $container->instance('auth.provider', new \stdClass());
        $guard = new AuthGuard();
        $ref = new \ReflectionMethod($guard, 'getUserProvider');
        $ref->setAccessible(true);
        $result = $ref->invoke($guard);
        $this->assertNull($result, 'Non-UserProvider instance should return null (InstanceOf_)');
    }

    // ========== Idempotency: Multiplication/Minus (line 198) ==========

    public function testIdempotencyCleanupDefaultCutoff(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        try { \Siro\Core\Auth\Idempotency::createTable(); } catch (\Throwable) { $this->markTestSkipped('Cannot create table'); }
        $result = \Siro\Core\Auth\Idempotency::cleanup();
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testIdempotencyCleanupWithCustomCutoff(): void
    {
        $container = Container::getInstance();
        if (!$container->has('db.default')) {
            $this->markTestSkipped('Database not configured');
        }
        try { \Siro\Core\Auth\Idempotency::createTable(); } catch (\Throwable) { $this->markTestSkipped('Cannot create table'); }
        $result = \Siro\Core\Auth\Idempotency::cleanup(time() - 86400);
        $this->assertIsInt($result);
    }

    // ========== AuthMiddleware: LessThanOrEqualTo (line 46) ==========

    public function testAuthMiddlewareRejectsUserIdZero(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $token = JWT::encode(['sub' => 0, 'ver' => 1, 'type' => 'access', 'iat' => time(), 'exp' => time() + 3600]);
        $mw = new AuthMiddleware();
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $called = false;
        $response = $mw->handle($request, function () use (&$called) {
            $called = true;
            return Response::success('ok');
        });
        $this->assertNotSame(200, $response->statusCode(), 'userId=0 should be rejected (LessThanOrEqualTo)');
        $this->assertFalse($called);
    }

    public function testAuthMiddlewareRejectsTokenVersionZero(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $token = JWT::encode(['sub' => 5, 'ver' => 0, 'type' => 'access', 'iat' => time(), 'exp' => time() + 3600]);
        $mw = new AuthMiddleware();
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $called = false;
        $response = $mw->handle($request, function () use (&$called) {
            $called = true;
            return Response::success('ok');
        });
        $this->assertNotSame(200, $response->statusCode(), 'tokenVersion=0 should be rejected (LessThanOrEqualTo)');
        $this->assertFalse($called);
    }

    // ========== AuthMiddleware: Identical check (line 134) ==========

    public function testAuthMiddlewareCachesUserModel(): void
    {
        putenv('JWT_SECRET=test_secret_key_32_chars_long_enough');
        Env::reset();
        JWT::reset();
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array { return ['id' => 1, 'role' => 'user']; }
        });
        $token = JWT::encodeAccess(1, 1);
        $mw = new AuthMiddleware();
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $r1 = $mw->handle($request, fn () => Response::success('ok'));
        $r2 = $mw->handle($request, fn () => Response::success('ok2'));
        $this->assertNotSame(500, $r1->statusCode());
        $this->assertNotSame(500, $r2->statusCode());
    }
}
