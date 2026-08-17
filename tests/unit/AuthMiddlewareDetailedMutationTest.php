<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\AuthMiddleware;
use Siro\Core\Model;
use Siro\Core\Request;
use Siro\Core\Response;

final class AuthUserD extends Model
{
    protected string $table = 'amw_users';
    /** @var array<int, string> */
    protected array $fillable = ['id', 'name', 'email', 'role', 'status', 'token_version', 'created_at', 'avatar'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        foreach (['identityMap', 'lastInsertId', 'queryLog'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue(null, []);
            }
        }
    }
}

/**
 * Strong tests killing AuthMiddleware mutants (full user flow).
 */
final class AuthMiddlewareDetailedMutationTest extends TestCase
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
        putenv('AUTH_USER_MODEL=' . AuthUserD::class);
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Database::execute('CREATE TABLE amw_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, role TEXT, status INTEGER, token_version INTEGER, created_at TEXT, avatar TEXT)');
        Database::table('amw_users')->insert([
            'id' => 1,
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'role' => 'admin',
            'status' => 1,
            'token_version' => 2,
            'created_at' => '2024-01-01',
            'avatar' => 'a.png',
        ]);
        AuthUserD::resetStatic();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('JWT_SECRET');
        putenv('AUTH_USER_MODEL');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        parent::tearDown();
    }

    private function request(string $auth): Request
    {
        $headers = [];
        if ($auth !== '') {
            $headers['authorization'] = $auth;
        }
        return new Request('GET', '/api/protected', [], $headers);
    }

    public function testFullAuthSuccessSetsUser(): void
    {
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success(['hi' => true]));
        $this->assertSame(200, $resp->statusCode());
        $user = $req->getAttribute('_auth_user');
        $this->assertInstanceOf(Model::class, $user);
        $this->assertSame('Alice', $user->getAttribute('name'));
    }

    public function testRoleMatchPasses(): void
    {
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success(), 'admin');
        $this->assertSame(200, $resp->statusCode());
    }

    public function testRoleMismatchForbidden(): void
    {
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success(), 'editor');
        $this->assertSame(403, $resp->statusCode());
        $payload = $resp->payload();
        $this->assertIsArray($payload);
    }

    public function testUserStatusInactive(): void
    {
        Database::table('amw_users')->where('id', 1)->update(['status' => 0]);
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testTokenVersionMismatch(): void
    {
        $token = JWT::encodeAccess(1, 99);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testUserNotFound(): void
    {
        $token = JWT::encodeAccess(999, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testZeroUserId(): void
    {
        $token = JWT::encode(['sub' => 0, 'ver' => 1, 'type' => JWT::TYPE_ACCESS]);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testZeroTokenVersion(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 0, 'type' => JWT::TYPE_ACCESS]);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testLowercaseBearer(): void
    {
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testWhitespaceAfterBearer(): void
    {
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer   ' . $token . '   ');
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testUserMissingFieldsFallbacks(): void
    {
        Database::table('amw_users')->where('id', 1)->update([
            'name' => 12345,
            'email' => null,
            'role' => null,
            'avatar' => null,
        ]);
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
        $user = $req->getAttribute('_auth_user');
        $this->assertNotNull($user);
    }

    public function testUserStatusZero(): void
    {
        Database::table('amw_users')->where('id', 1)->update(['status' => '0']);
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testUserStatusNumericString(): void
    {
        Database::table('amw_users')->where('id', 1)->update(['status' => '1']);
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testUserNonNumericStatus(): void
    {
        Database::table('amw_users')->where('id', 1)->update(['status' => 'active']);
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testUserTokenVersionString(): void
    {
        Database::table('amw_users')->where('id', 1)->update(['token_version' => '2']);
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testRoleCaseInsensitiveMatch(): void
    {
        Database::table('amw_users')->where('id', 1)->update(['role' => 'ADMIN']);
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success(), 'admin');
        $this->assertSame(200, $resp->statusCode());
    }

    public function testRoleWhitespaceTrim(): void
    {
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success(), '  admin  ');
        $this->assertSame(200, $resp->statusCode());
    }

    public function testRoleMultipleWithMatch(): void
    {
        $token = JWT::encodeAccess(1, 2);
        $mw = new AuthMiddleware();
        $req = $this->request('Bearer ' . $token);
        $resp = $mw->handle($req, fn () => Response::success(), 'editor', 'admin', 'viewer');
        $this->assertSame(200, $resp->statusCode());
    }
}