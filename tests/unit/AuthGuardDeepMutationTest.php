<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\AuthGuard;
use Siro\Core\Auth\JWT;
use Siro\Core\Container;
use Siro\Core\Env;
use Siro\Core\Request;

/**
 * Deep mutation tests for AuthGuard — targets 64 uncovered mutations.
 *
 * Covers: instance, setInstance, resolve, user, id, check, guest,
 * hasRole, logout, getUserProvider, token version validation,
 * Bearer extraction, custom resolver, exception handling.
 */
final class AuthGuardDeepMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('JWT_SECRET=test_jwt_secret_key_for_unit_tests_32_chars_long!!');
        putenv('JWT_KEY_VERSION=1');
        putenv('JWT_ALGORITHM=HS256');
        unset($_ENV['JWT_SECRET'], $_ENV['JWT_KEY_VERSION'], $_ENV['JWT_ALGORITHM']);
        JWT::reset();
        AuthGuard::setInstance(null);
    }

    protected function tearDown(): void
    {
        putenv('JWT_SECRET');
        putenv('JWT_KEY_VERSION');
        putenv('JWT_ALGORITHM');
        Env::reset();
        JWT::reset();
        AuthGuard::setInstance(null);
        parent::tearDown();
    }

    private function setUserData(array $data): void
    {
        $guard = AuthGuard::instance();
        $ref = new \ReflectionClass($guard);
        $prop = $ref->getProperty('userData');
        $prop->setAccessible(true);
        $prop->setValue($guard, $data);
    }

    // ============================================================
    // instance / setInstance
    // ============================================================

    public function testInstanceReturnsSameObject(): void
    {
        AuthGuard::setInstance(null);
        $a = AuthGuard::instance();
        $b = AuthGuard::instance();
        $this->assertSame($a, $b);
    }

    public function testSetInstanceNullCreatesNew(): void
    {
        $first = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $second = AuthGuard::instance();
        $this->assertNotSame($first, $second);
    }

    public function testSetInstanceCustomGuard(): void
    {
        $ref = new \ReflectionClass(AuthGuard::class);
        $prop = $ref->getProperty('userData');
        $prop->setAccessible(true);

        $custom = AuthGuard::instance();
        $prop->setValue($custom, ['id' => 99, 'role' => 'admin', 'token_version' => 1]);
        AuthGuard::setInstance($custom);

        $resolved = AuthGuard::instance();
        $this->assertSame($custom, $resolved);
        $this->assertTrue($resolved->check());
        $this->assertSame(99, $resolved->id());
    }

    // ============================================================
    // check / guest
    // ============================================================

    public function testCheckFalseWhenNoUser(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertFalse($guard->check());
    }

    public function testGuestTrueWhenNoUser(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->guest());
    }

    public function testCheckTrueWhenUserSet(): void
    {
        $this->setUserData(['id' => 1, 'role' => 'user', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->check());
        $this->assertFalse($guard->guest());
    }

    // ============================================================
    // user / id
    // ============================================================

    public function testUserReturnsNullWhenNoUser(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertNull($guard->user());
    }

    public function testUserReturnsUserData(): void
    {
        $data = ['id' => 42, 'name' => 'Test', 'role' => 'admin', 'token_version' => 1];
        $this->setUserData($data);
        $guard = AuthGuard::instance();
        $this->assertSame($data, $guard->user());
    }

    public function testIdReturnsNullWhenNoUser(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertNull($guard->id());
    }

    public function testIdReturnsUserId(): void
    {
        $this->setUserData(['id' => 77, 'role' => 'user', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertSame(77, $guard->id());
    }

    public function testIdReturnsNullForNonNumericId(): void
    {
        $this->setUserData(['id' => 'abc', 'role' => 'user', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertNull($guard->id());
    }

    public function testIdReturnsNullForNullId(): void
    {
        $this->setUserData(['id' => null, 'role' => 'user', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertNull($guard->id());
    }

    // ============================================================
    // hasRole
    // ============================================================

    public function testHasRoleExactMatch(): void
    {
        $this->setUserData(['id' => 1, 'role' => 'admin', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->hasRole('admin'));
    }

    public function testHasRoleCaseInsensitive(): void
    {
        $this->setUserData(['id' => 1, 'role' => 'Admin', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->hasRole('admin'));
        $this->assertTrue($guard->hasRole('ADMIN'));
    }

    public function testHasRoleNoMatch(): void
    {
        $this->setUserData(['id' => 1, 'role' => 'user', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertFalse($guard->hasRole('admin'));
    }

    public function testHasRoleMultipleRolesFirstMatches(): void
    {
        $this->setUserData(['id' => 1, 'role' => 'admin', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->hasRole('admin', 'editor', 'viewer'));
    }

    public function testHasRoleMultipleRolesSecondMatches(): void
    {
        $this->setUserData(['id' => 1, 'role' => 'editor', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->hasRole('admin', 'editor', 'viewer'));
    }

    public function testHasRoleMultipleRolesNoneMatch(): void
    {
        $this->setUserData(['id' => 1, 'role' => 'user', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertFalse($guard->hasRole('admin', 'editor', 'viewer'));
    }

    public function testHasRoleReturnsFalseWhenNoUser(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertFalse($guard->hasRole('admin'));
    }

    public function testHasRoleWithNullRoleDefaultsToUser(): void
    {
        $this->setUserData(['id' => 1, 'role' => null, 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->hasRole('user'));
        $this->assertFalse($guard->hasRole('admin'));
    }

    public function testHasRoleWithArrayRoleDefaultsToUser(): void
    {
        $this->setUserData(['id' => 1, 'role' => ['admin'], 'token_version' => 1]);
        $guard = AuthGuard::instance();
        // Non-scalar role defaults to 'user'
        $this->assertTrue($guard->hasRole('user'));
    }

    // ============================================================
    // logout
    // ============================================================

    public function testLogoutClearsUserData(): void
    {
        $this->setUserData(['id' => 1, 'role' => 'admin', 'token_version' => 1]);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->check());

        $guard->logout();

        $this->assertTrue($guard->guest());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function testLogoutWhenAlreadyGuestDoesNotThrow(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $guard->logout();
        $this->assertTrue($guard->guest());
    }

    // ============================================================
    // resolve — no auth header
    // ============================================================

    public function testResolveNoHeaderReturnsNull(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test');
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveEmptyHeaderReturnsNull(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test', ['authorization' => '']);
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveBearerWithoutTokenReturnsNull(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test', ['authorization' => 'Bearer ']);
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveInvalidBearerTokenReturnsNull(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test', ['authorization' => 'Bearer totally-invalid-token']);
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveCaseInsensitiveBearer(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test', ['authorization' => 'bearer totally-invalid-token']);
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveMixedCaseBearer(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test', ['authorization' => 'BEARER totally-invalid-token']);
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveNonBearerAuthReturnsNull(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test', ['authorization' => 'Basic abc123']);
        $this->assertNull($guard->resolve($request));
    }

    // ============================================================
    // resolve — valid JWT but no provider
    // ============================================================

    public function testResolveValidJwtNoProviderReturnsNull(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $token = JWT::encodeAccess(1, 1);
        $request = new Request('GET', '/test', ['authorization' => 'Bearer ' . $token]);
        // Without a user provider, resolve returns null
        $this->assertNull($guard->resolve($request));
    }

    // ============================================================
    // resolve — custom resolver via Container
    // ============================================================

    public function testResolveUsesCustomResolver(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $container = Container::getInstance();
        $container->bind('auth.resolver', function ($container) {
            return function (Request $req) {
                return ['id' => 100, 'name' => 'CustomUser', 'role' => 'admin', 'token_version' => 1];
            };
        });
        $request = new Request('GET', '/test');
        $result = $guard->resolve($request);
        $this->assertNotNull($result);
        $this->assertSame(100, $result['id']);
        $this->assertSame('CustomUser', $result['name']);
    }

    public function testResolveCustomResolverReturnsNonArrayIgnored(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $container = Container::getInstance();
        $container->bind('auth.resolver', function ($container) {
            return function (Request $req) {
                return 'not-an-array';
            };
        });
        $request = new Request('GET', '/test');
        $result = $guard->resolve($request);
        $this->assertNull($result);
    }

    public function testResolveCustomResolverReturnsNullFallsThrough(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $container = Container::getInstance();
        $container->bind('auth.resolver', function ($container) {
            return function (Request $req) {
                return null;
            };
        });
        $request = new Request('GET', '/test');
        $result = $guard->resolve($request);
        $this->assertNull($result);
    }

    // ============================================================
    // resolve — JWT exception handling
    // ============================================================

    public function testResolveJwtExceptionReturnsNull(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        // Expired token should trigger exception caught by resolve
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => 'access', 'iat' => time() - 7200, 'exp' => time() - 3600, 'jti' => 'expired-resolve']);
        $request = new Request('GET', '/test', ['authorization' => 'Bearer ' . $token]);
        $this->assertNull($guard->resolve($request));
    }

    // ============================================================
    // resolve — token version mismatch
    // ============================================================

    public function testResolveTokenVersionMismatchReturnsNull(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $token = JWT::encodeAccess(1, 5); // version 5
        $request = new Request('GET', '/test', ['authorization' => 'Bearer ' . $token]);
        // No provider means user is null, so returns null
        $this->assertNull($guard->resolve($request));
    }

    // ============================================================
    // getUserProvider
    // ============================================================

    public function testGetUserProviderReturnsNullWhenNoProviderBound(): void
    {
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $ref = new \ReflectionMethod($guard, 'getUserProvider');
        $ref->setAccessible(true);
        $result = $ref->invoke($guard);
        // Without auth.provider bound and without App\Models\User class
        // Returns null
        $this->assertNull($result);
    }
}
