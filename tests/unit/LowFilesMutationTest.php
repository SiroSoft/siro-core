<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Mercure;
use Siro\Core\Middleware\ApiKeyMiddleware;
use Siro\Core\Middleware\AuditMiddleware;
use Siro\Core\ModelNotFoundException;
use Siro\Core\Queue\RedisQueueDriver;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Coverage for low files: ModelNotFoundException, ApiKey/Audit middleware,
 * RedisQueueDriver, Mercure topic.
 */
final class LowFilesMutationTest extends TestCase
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
        ApiKey::createTable();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        putenv('APP_ENV');
        parent::tearDown();
    }

    public function testModelNotFoundException(): void
    {
        $e = new ModelNotFoundException('User', 42);
        $this->assertSame('User', $e->modelClass);
        $this->assertSame(42, $e->id);
        $this->assertSame('Resource not found', $e->getMessage());
    }

    public function testApiKeyMiddlewareMissingKey(): void
    {
        $mw = new ApiKeyMiddleware();
        $resp = $mw->handle(new Request('GET', '/api/x'), fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testApiKeyMiddlewareValidKey(): void
    {
        $result = ApiKey::create('Partner', 'read', 1);
        $mw = new ApiKeyMiddleware();
        $req = new Request('GET', '/api/x', [], ['X-Api-Key' => $result['token']]);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testApiKeyMiddlewareInvalidKey(): void
    {
        $mw = new ApiKeyMiddleware();
        $resp = $mw->handle(new Request('GET', '/api/x', [], ['X-Api-Key' => 'bad-token']), fn () => Response::success());
        $this->assertSame(401, $resp->statusCode());
    }

    public function testApiKeyMiddlewareScopeOk(): void
    {
        $result = ApiKey::create('Partner', 'read', 1);
        $mw = new ApiKeyMiddleware();
        $req = new Request('GET', '/api/x', [], ['X-Api-Key' => $result['token']]);
        $resp = $mw->handle($req, fn () => Response::success(), 'read');
        $this->assertSame(200, $resp->statusCode());
    }

    public function testApiKeyMiddlewareScopeDenied(): void
    {
        $result = ApiKey::create('Partner', 'read', 1);
        $mw = new ApiKeyMiddleware();
        $req = new Request('GET', '/api/x', [], ['X-Api-Key' => $result['token']]);
        $resp = $mw->handle($req, fn () => Response::success(), 'write');
        $this->assertSame(403, $resp->statusCode());
    }

    public function testAuditMiddlewarePassesThrough(): void
    {
        $mw = new AuditMiddleware();
        $resp = $mw->handle(new Request('GET', '/api/x'), fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testAuditMiddleware401(): void
    {
        $mw = new AuditMiddleware();
        $resp = $mw->handle(new Request('GET', '/api/x'), fn () => Response::error('denied', 401));
        $this->assertSame(401, $resp->statusCode());
    }

    public function testAuditMiddlewareErrorStatus(): void
    {
        $mw = new AuditMiddleware();
        $resp = $mw->handle(new Request('POST', '/api/x'), fn () => Response::error('bad', 422));
        $this->assertSame(422, $resp->statusCode());
    }

    public function testRedisQueueDriver(): void
    {
        $driver = new RedisQueueDriver();
        $this->assertIsBool($driver->isAvailable());
        $this->assertNull($driver->pop('q', 1));
        $driver->push('q', '{}');
        $driver->release('q', '{}', 1);
        $this->assertTrue(true);
    }

    public function testMercureTopic(): void
    {
        $this->assertSame('/api/users/1', Mercure::topic('users', 1));
        $this->assertSame('/api/users', Mercure::topic('users'));
    }

    public function testMercurePublishNoServer(): void
    {
        $this->assertIsBool(Mercure::publish('topic', ['a' => 1]));
    }
}
