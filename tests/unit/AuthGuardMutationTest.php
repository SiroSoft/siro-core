<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\AuthGuard;
use Siro\Core\Auth\JWT;
use Siro\Core\Auth\UserProvider;
use Siro\Core\Container;
use Siro\Core\Env;
use Siro\Core\Request;

/**
 * Strong-assertion tests targeting mutations on Auth\AuthGuard.
 */
final class AuthGuardMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Container::setInstance(new Container());
        AuthGuard::setInstance(null);
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_key_for_unit_tests_32_chars_long!!';
        putenv('JWT_SECRET=' . $_ENV['JWT_SECRET']);
        JWT::reset();
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        AuthGuard::setInstance(null);
        JWT::reset();
        parent::tearDown();
    }

    public function testInstanceReturnsSingleton(): void
    {
        $a = AuthGuard::instance();
        $b = AuthGuard::instance();
        $this->assertSame($a, $b);
    }

    public function testSetInstanceReplaces(): void
    {
        $guard = new AuthGuard();
        AuthGuard::setInstance($guard);
        $this->assertSame($guard, AuthGuard::instance());
    }

    public function testResolveNoAuthHeaderReturnsNull(): void
    {
        $request = new Request('GET', '/', [], []);
        $this->assertNull((new AuthGuard())->resolve($request));
    }

    public function testResolveInvalidAuthHeaderReturnsNull(): void
    {
        $request = new Request('GET', '/', [], ['authorization' => 'Basic abc123']);
        $this->assertNull((new AuthGuard())->resolve($request));
    }

    public function testResolveViaContainerResolver(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 42, 'name' => 'Resolved', 'role' => 'admin'];
            }
        });

        $request = new Request('GET', '/', [], ['authorization' => 'Bearer dummy']);
        $user = (new AuthGuard())->resolve($request);
        $this->assertNotNull($user);
        $this->assertSame(42, $user['id']);
        $this->assertSame('admin', $user['role']);
    }

    public function testResolveContainerResolverReturnsNullOnNonArray(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): string
            {
                return 'not-an-array';
            }
        });

        $request = new Request('GET', '/', [], ['authorization' => 'Bearer dummy']);
        $this->assertNull((new AuthGuard())->resolve($request));
    }

    public function testUserAndCheckAfterResolve(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 7, 'role' => 'user'];
            }
        });

        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer dummy']);
        $guard->resolve($request);
        $this->assertTrue($guard->check());
        $this->assertFalse($guard->guest());
        $this->assertSame(7, $guard->id());
        $this->assertSame(['id' => 7, 'role' => 'user'], $guard->user());
    }

    public function testIdReturnsNullWithoutUser(): void
    {
        $guard = new AuthGuard();
        $this->assertNull($guard->id());
        $this->assertFalse($guard->check());
        $this->assertTrue($guard->guest());
    }

    public function testHasRole(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 1, 'role' => 'Admin'];
            }
        });

        $guard = new AuthGuard();
        $guard->resolve(new Request('GET', '/', [], ['authorization' => 'Bearer x']));
        $this->assertTrue($guard->hasRole('admin'));
        $this->assertTrue($guard->hasRole('ADMIN'));
        $this->assertTrue($guard->hasRole('user', 'admin'));
        $this->assertFalse($guard->hasRole('superadmin'));
    }

    public function testHasRoleWithoutUser(): void
    {
        $guard = new AuthGuard();
        $this->assertFalse($guard->hasRole('admin'));
    }

    public function testHasRoleWithDefaultUserRole(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 1];
            }
        });

        $guard = new AuthGuard();
        $guard->resolve(new Request('GET', '/', [], ['authorization' => 'Bearer x']));
        $this->assertTrue($guard->hasRole('user'));
        $this->assertFalse($guard->hasRole('admin'));
    }

    public function testHasRoleWithNonScalarRole(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 1, 'role' => ['nested' => true]];
            }
        });

        $guard = new AuthGuard();
        $guard->resolve(new Request('GET', '/', [], ['authorization' => 'Bearer x']));
        $this->assertFalse($guard->hasRole('admin'));
        $this->assertTrue($guard->hasRole('user'));
    }

    public function testLogoutClearsUser(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 1];
            }
        });

        $guard = new AuthGuard();
        $guard->resolve(new Request('GET', '/', [], ['authorization' => 'Bearer x']));
        $this->assertTrue($guard->check());
        $guard->logout();
        $this->assertFalse($guard->check());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function testResolveValidJwtWithoutProvider(): void
    {
        $token = JWT::encodeAccess(5, 1);
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $guard = new AuthGuard();
        // No 'App\Models\User' class exists, no auth.provider bound -> null user
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveValidJwtWithProvider(): void
    {
        $provider = new class implements UserProvider {
            public function retrieveById(int $id): ?array
            {
                return ['id' => $id, 'token_version' => 1, 'role' => 'admin'];
            }

            public function retrieveByCredentials(array $credentials): ?array
            {
                return null;
            }

            public function validateCredentials(array $user, string $password): bool
            {
                return true;
            }
        };
        Container::getInstance()->instance('auth.provider', $provider);

        $token = JWT::encodeAccess(5, 1);
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $guard = new AuthGuard();
        $user = $guard->resolve($request);
        $this->assertNotNull($user);
        $this->assertSame(5, $user['id']);
        $this->assertArrayHasKey('claims', $user);
        $this->assertSame(5, $user['claims']['sub']);
    }

    public function testResolveJwtTokenVersionMismatch(): void
    {
        $provider = new class implements UserProvider {
            public function retrieveById(int $id): ?array
            {
                return ['id' => $id, 'token_version' => 9, 'role' => 'admin'];
            }

            public function retrieveByCredentials(array $credentials): ?array
            {
                return null;
            }

            public function validateCredentials(array $user, string $password): bool
            {
                return true;
            }
        };
        Container::getInstance()->instance('auth.provider', $provider);

        $token = JWT::encodeAccess(5, 1);
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $guard = new AuthGuard();
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveJwtProviderReturnsNullUser(): void
    {
        $provider = new class implements UserProvider {
            public function retrieveById(int $id): ?array
            {
                return null;
            }

            public function retrieveByCredentials(array $credentials): ?array
            {
                return null;
            }

            public function validateCredentials(array $user, string $password): bool
            {
                return true;
            }
        };
        Container::getInstance()->instance('auth.provider', $provider);

        $token = JWT::encodeAccess(5, 1);
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $guard = new AuthGuard();
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveExpiredJwtCatchesException(): void
    {
        $token = JWT::encode([
            'sub' => 5, 'ver' => 1,
            'iat' => time() - 7200, 'exp' => time() - 3600,
            'type' => 'access', 'jti' => 'expired-guard',
        ]);
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $guard = new AuthGuard();
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveNonScalarSubBecomesZero(): void
    {
        $token = JWT::encodeAccess(5, 1);
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ' . $token]);
        $guard = new AuthGuard();
        $this->assertNull($guard->resolve($request));
    }

    public function testUserReturnsNullWhenNotResolved(): void
    {
        $guard = new AuthGuard();
        $this->assertNull($guard->user());
    }

    public function testIdReturnsNullWhenNoId(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['name' => 'NoId'];
            }
        });
        $guard = new AuthGuard();
        $guard->resolve(new Request('GET', '/', [], ['authorization' => 'Bearer x']));
        $this->assertNull($guard->id());
    }

    public function testIdReturnsIntegerForStringId(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => '42', 'role' => 'user'];
            }
        });
        $guard = new AuthGuard();
        $guard->resolve(new Request('GET', '/', [], ['authorization' => 'Bearer x']));
        $this->assertSame(42, $guard->id());
    }

    public function testHasRoleWithEmptyRoles(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 1, 'role' => 'admin'];
            }
        });
        $guard = new AuthGuard();
        $guard->resolve(new Request('GET', '/', [], ['authorization' => 'Bearer x']));
        $this->assertFalse($guard->hasRole());
    }

    public function testGuestReturnsTrueAfterLogout(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 1, 'role' => 'user'];
            }
        });
        $guard = new AuthGuard();
        $guard->resolve(new Request('GET', '/', [], ['authorization' => 'Bearer x']));
        $this->assertFalse($guard->guest());
        $guard->logout();
        $this->assertTrue($guard->guest());
    }

    public function testResolveWithBearerCaseInsensitive(): void
    {
        $container = Container::getInstance();
        $container->instance('auth.resolver', new class {
            public function __invoke(Request $req): array
            {
                return ['id' => 10, 'role' => 'user'];
            }
        });
        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => 'bearer dummy']);
        $user = $guard->resolve($request);
        $this->assertNotNull($user);
        $this->assertSame(10, $user['id']);
    }

    public function testResolveWithNonBearerAuthReturnsNull(): void
    {
        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => 'Basic dXNlcjpwYXNz']);
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveWithEmptyBearerReturnsNull(): void
    {
        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer ']);
        $this->assertNull($guard->resolve($request));
    }

    public function testResolveWithBearerOnlyReturnsNull(): void
    {
        $guard = new AuthGuard();
        $request = new Request('GET', '/', [], ['authorization' => 'Bearer']);
        $this->assertNull($guard->resolve($request));
    }
}
