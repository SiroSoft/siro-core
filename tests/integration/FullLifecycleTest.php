<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\Route;
use Siro\Core\Env;
use Siro\Core\Config;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Logger;
use Siro\Core\Validator;
use Siro\Core\Auth\JWT;

/**
 * Comprehensive integration and stress test suite for the Siro framework.
 *
 * Covers: full request lifecycle, auth flow, database CRUD/transactions,
 * validation, events, caching, maintenance mode, error handling,
 * middleware pipeline, high-volume routes, dynamic routing, and performance.
 */
final class FullLifecycleTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        Env::reset();
        Database::purgeAll();
        Logger::reset();
        Event::flush();

        $this->router = new Router();
        Route::setRouter($this->router);
        Route::clearRoutes();
    }

    // =========================================================================
    // 1. FULL REQUEST LIFECYCLE
    // =========================================================================

    public function testFullRequestLifecycle(): void
    {
        $this->router->get('/health', function () {
            return Response::success(['status' => 'ok'], 'Healthy');
        });

        $request = new Request('GET', '/health');
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode());
        $payload = $response->payload();
        $this->assertTrue($payload['success'] ?? false);
        $this->assertEquals('Healthy', $payload['message'] ?? '');
    }

    // =========================================================================
    // 2. MIDDLEWARE PIPELINE
    // =========================================================================

    public function testMiddlewarePipelineExecution(): void
    {
        $order = [];

        $this->router->get('/middleware-test', function () use (&$order) {
            return Response::success(['order' => $order]);
        }, [
            function (Request $request, callable $next) use (&$order) {
                $order[] = 'first';
                return $next($request);
            },
        ]);

        $request = new Request('GET', '/middleware-test');
        $response = $this->router->dispatch($request);

        $this->assertEquals(200, $response->statusCode());
        $this->assertEquals(['first'], $order);
    }

    // =========================================================================
    // 3. AUTH FLOW (JWT)
    // =========================================================================

    public function testJwtAuthFlow(): void
    {
        putenv('JWT_ALGORITHM=HS256');
        putenv('JWT_SECRET=' . bin2hex(random_bytes(32)));

        $token = JWT::encodeAccess(1, 1, 3600);
        $claims = JWT::decode($token);

        $this->assertEquals(1, $claims['sub']);
        $this->assertEquals(1, $claims['ver']);
        $this->assertEquals(JWT::TYPE_ACCESS, $claims['type']);
        $this->assertArrayHasKey('jti', $claims);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertArrayHasKey('exp', $claims);
    }

    public function testRefreshTokenRotation(): void
    {
        putenv('JWT_ALGORITHM=HS256');
        putenv('JWT_SECRET=' . bin2hex(random_bytes(32)));

        $jti1 = bin2hex(random_bytes(16));
        $jti2 = bin2hex(random_bytes(16));
        $refreshToken1 = JWT::encodeRefresh(1, 1, 604800, $jti1);
        $refreshToken2 = JWT::encodeRefresh(1, 1, 604800, $jti2);

        $claims1 = JWT::decode($refreshToken1);
        $claims2 = JWT::decode($refreshToken2);

        $this->assertEquals(JWT::TYPE_REFRESH, $claims1['type']);
        $this->assertEquals(JWT::TYPE_REFRESH, $claims2['type']);
        $this->assertNotEquals($claims1['jti'], $claims2['jti']);
    }

    // =========================================================================
    // 4. DATABASE INTEGRATION (CRUD + Transactions)
    // =========================================================================

    public function testDatabaseCrudOperations(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Database::execute('CREATE TABLE crud_test (id INTEGER PRIMARY KEY, name TEXT, value INTEGER)');

        Database::execute('INSERT INTO crud_test (name, value) VALUES (:name, :value)', ['name' => 'test', 'value' => 42]);
        $rows = Database::select('SELECT * FROM crud_test WHERE name = :name', ['name' => 'test']);
        $this->assertCount(1, $rows);
        $this->assertEquals(42, $rows[0]['value']);

        Database::execute('UPDATE crud_test SET value = :value WHERE name = :name', ['value' => 99, 'name' => 'test']);
        $rows = Database::select('SELECT * FROM crud_test WHERE name = :name', ['name' => 'test']);
        $this->assertEquals(99, $rows[0]['value']);

        Database::execute('DELETE FROM crud_test WHERE name = :name', ['name' => 'test']);
        $rows = Database::select('SELECT * FROM crud_test WHERE name = :name', ['name' => 'test']);
        $this->assertCount(0, $rows);
    }

    public function testDatabaseTransactionWithRollback(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Database::execute('CREATE TABLE tx_test (id INTEGER PRIMARY KEY, name TEXT)');

        $exceptionCaught = false;
        try {
            Database::transaction(function () {
                Database::execute("INSERT INTO tx_test (name) VALUES (:name)", ['name' => 'should_rollback']);
                throw new \RuntimeException('Force rollback');
            });
        } catch (\RuntimeException) {
            $exceptionCaught = true;
        }

        $this->assertTrue($exceptionCaught);
        $rows = Database::select('SELECT * FROM tx_test');
        $this->assertCount(0, $rows, 'Transaction should be rolled back');
    }

    // =========================================================================
    // 5. VALIDATION INTEGRATION
    // =========================================================================

    public function testValidatorReturnsErrorsForInvalidData(): void
    {
        $errors = Validator::make(
            ['email' => 'invalid-email', 'age' => 'abc'],
            ['email' => 'required|email', 'age' => 'required|numeric|min:18|max:150']
        );

        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    public function testValidatorPassesForValidData(): void
    {
        $errors = Validator::make(
            ['email' => 'test@example.com', 'age' => '25'],
            ['email' => 'required|email', 'age' => 'required|numeric|min:18|max:150']
        );

        $this->assertEmpty($errors);
    }

    // =========================================================================
    // 6. EVENT SYSTEM INTEGRATION
    // =========================================================================

    public function testEventDispatching(): void
    {
        $dispatched = [];
        Event::on('test.event', function ($payload) use (&$dispatched) {
            $dispatched[] = $payload;
        });

        Event::emit('test.event', 'hello');
        $this->assertCount(1, $dispatched);
        $this->assertEquals('hello', $dispatched[0]);
    }

    public function testEventWildcardMatching(): void
    {
        $events = [];
        Event::on('user.*', function ($payload) use (&$events) {
            $events[] = Event::currentEvent();
        });

        Event::emit('user.created', ['id' => 1]);
        Event::emit('user.updated', ['id' => 1]);

        $this->assertCount(2, $events);
        $this->assertEquals('user.created', $events[0]);
        $this->assertEquals('user.updated', $events[1]);
    }

    // =========================================================================
    // 7. STRESS TESTS (high route count, performance, edge cases)
    // =========================================================================

    public function testHighNumberOfRoutes(): void
    {
        $router = new Router();
        for ($i = 0; $i < 500; $i++) {
            $router->get('/route-' . $i, function () use ($i) {
                return Response::success(['id' => $i]);
            });
        }

        $request = new Request('GET', '/route-499');
        $response = $router->dispatch($request);

        $this->assertEquals(200, $response->statusCode());
    }

    public function testDynamicRouteWithManyParams(): void
    {
        $router = new Router();
        $router->get('/posts/{year}/{month}/{slug}', function (Request $req) {
            return Response::success([
                'year' => $req->param('year'),
                'month' => $req->param('month'),
                'slug' => $req->param('slug'),
            ]);
        });

        $request = new Request('GET', '/posts/2026/05/hello-world');
        $response = $router->dispatch($request);

        $this->assertEquals(200, $response->statusCode());
        $payload = $response->payload();
        $this->assertEquals('2026', $payload['data']['year']);
        $this->assertEquals('05', $payload['data']['month']);
        $this->assertEquals('hello-world', $payload['data']['slug']);
    }

    public function testRouteNotFoundReturns404(): void
    {
        $router = new Router();
        $request = new Request('GET', '/nonexistent-path');
        $response = $router->dispatch($request);

        $this->assertEquals(404, $response->statusCode());
    }

    public function testStaticRouteDispatchPerformance(): void
    {
        $router = new Router();
        for ($i = 0; $i < 100; $i++) {
            $router->get('/static/' . $i, function () {
                return Response::success(['ok' => true]);
            });
        }

        $start = microtime(true);
        $iterations = 1000;
        for ($i = 0; $i < $iterations; $i++) {
            $request = new Request('GET', '/static/' . ($i % 100));
            $router->dispatch($request);
        }
        $elapsed = (microtime(true) - $start) * 1000 / $iterations;

        $this->assertLessThan(1.0, $elapsed, 'Static route dispatch avg: ' . round($elapsed, 4) . 'ms');
    }

    // =========================================================================
    // 8. CACHE INTEGRATION
    // =========================================================================

    public function testCacheFileDriverSetGetForget(): void
    {
        $basePath = dirname(__DIR__, 2);
        \Siro\Core\Cache::boot($basePath);

        $key = 'test_key_' . bin2hex(random_bytes(4));
        $value = ['data' => 'test_value', 'number' => 42];

        \Siro\Core\Cache::set($key, $value, 60);
        $retrieved = \Siro\Core\Cache::get($key);

        $this->assertEquals($value, $retrieved);
        \Siro\Core\Cache::forget($key);
        $this->assertNull(\Siro\Core\Cache::get($key));
    }

    // =========================================================================
    // 9. MAINTENANCE MODE
    // =========================================================================

    public function testMaintenanceModeDetection(): void
    {
        $downFile = dirname(__DIR__, 2) . '/storage/framework/down';
        if (file_exists($downFile)) {
            $data = App::isDown();
            $this->assertIsArray($data);
        } else {
            $this->assertNull(App::isDown());
        }
    }

    // =========================================================================
    // 10. ERROR HANDLING
    // =========================================================================

    public function testExceptionInRouteHandlerPropagates(): void
    {
        $router = new Router();
        $router->get('/error', function () {
            throw new \RuntimeException('Test error');
        });

        $this->expectException(\RuntimeException::class);
        $request = new Request('GET', '/error');
        $router->dispatch($request);
    }

    public function test404WithDynamicRoutesDoesNotMatchPartialPaths(): void
    {
        $router = new Router();
        $router->get('/users/{id}', function (Request $req) {
            return Response::success(['id' => $req->param('id')]);
        });

        $request = new Request('GET', '/users/123/posts');
        $response = $router->dispatch($request);
        $this->assertEquals(404, $response->statusCode());
    }
}
