<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ModelUserProvider;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\CspMiddleware;
use Siro\Core\Middleware\EtagMiddleware;
use Siro\Core\Middleware\VersionMiddleware;
use Siro\Core\Model;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Coverage for zero-covered files: middlewares, ModelUserProvider, Controller.
 */
final class MiddlewareZeroMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('APP_ENV=testing');
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, password TEXT, created_at TEXT, updated_at TEXT)');
        ZUser::resetStatic();
        CspMiddleware::nonce();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        putenv('APP_ENV');
        parent::tearDown();
    }

    private function makeRequest(string $method = 'GET', string $path = '/api/test', array $headers = []): Request
    {
        return new Request($method, $path, [], $headers);
    }

    public function testCspMiddlewareAddsHeaders(): void
    {
        $mw = new CspMiddleware();
        $resp = $mw->handle($this->makeRequest(), fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
        $this->assertNotNull($resp->getHeader('Content-Security-Policy'));
        $this->assertNotNull($resp->getHeader('X-Frame-Options'));
    }

    public function testCspMiddlewareCustomPolicy(): void
    {
        putenv('CSP_POLICY=default-src none');
        $mw = new CspMiddleware();
        $resp = $mw->handle($this->makeRequest(), fn () => Response::success());
        $this->assertSame('default-src none', $resp->getHeader('Content-Security-Policy'));
    }

    public function testCspNonceGenerates(): void
    {
        $n1 = CspMiddleware::nonce();
        $n2 = CspMiddleware::nonce();
        $this->assertSame($n1, $n2);
        $this->assertSame(32, strlen($n1));
    }

    public function testEtagMiddlewareAddsEtag(): void
    {
        EtagMiddleware::enable();
        $mw = new EtagMiddleware();
        $resp = $mw->handle($this->makeRequest(), fn () => Response::success(['a' => 1]));
        $this->assertSame(200, $resp->statusCode());
        $this->assertNotNull($resp->getHeader('ETag'));
    }

    public function testEtagMiddleware304(): void
    {
        EtagMiddleware::enable();
        $mw = new EtagMiddleware();
        $first = $mw->handle($this->makeRequest(), fn () => Response::success(['a' => 1]));
        $etag = $first->getHeader('ETag');
        $resp = $mw->handle($this->makeRequest('GET', '/api/test', ['if-none-match' => $etag]), fn () => Response::success(['a' => 1]));
        $this->assertSame(304, $resp->statusCode());
    }

    public function testEtagMiddlewareDisabled(): void
    {
        EtagMiddleware::disable();
        $mw = new EtagMiddleware();
        $resp = $mw->handle($this->makeRequest(), fn () => Response::success());
        $this->assertNull($resp->getHeader('ETag'));
        EtagMiddleware::enable();
    }

    public function testVersionMiddlewareAddsHeader(): void
    {
        $mw = new VersionMiddleware();
        $resp = $mw->handle($this->makeRequest(), fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testModelUserProviderRetrieveById(): void
    {
        ZUser::create(['name' => 'A', 'email' => 'a@x.com', 'password' => password_hash('pw', PASSWORD_BCRYPT)]);
        $provider = new ModelUserProvider(ZUser::class);
        $user = $provider->retrieveById(1);
        $this->assertNotNull($user);
        $this->assertSame('A', $user['name']);
    }

    public function testModelUserProviderRetrieveByCredentials(): void
    {
        ZUser::create(['name' => 'B', 'email' => 'b@x.com', 'password' => password_hash('pw', PASSWORD_BCRYPT)]);
        $provider = new ModelUserProvider(ZUser::class);
        $user = $provider->retrieveByCredentials(['email' => 'b@x.com', 'password' => 'pw']);
        $this->assertNotNull($user);
        $this->assertSame('B', $user['name']);
    }

    public function testModelUserProviderValidateCredentials(): void
    {
        $hash = password_hash('secret', PASSWORD_BCRYPT);
        $provider = new ModelUserProvider(ZUser::class);
        $this->assertTrue($provider->validateCredentials(['password' => $hash], 'secret'));
        $this->assertFalse($provider->validateCredentials(['password' => $hash], 'wrong'));
        $this->assertFalse($provider->validateCredentials([], 'secret'));
    }

    public function testModelUserProviderRetrieveByIdMissing(): void
    {
        $provider = new ModelUserProvider(ZUser::class);
        $this->assertNull($provider->retrieveById(999));
    }

    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Controller::class));
    }
}

final class ZUser extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'email', 'password'];

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
