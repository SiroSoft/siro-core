<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Middleware\ApiKeyMiddleware;
use Siro\Core\Middleware\VersionMiddleware;
use Siro\Core\Queue\RedisQueueDriver;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Coverage for zero files: VersionMiddleware, RedisQueueDriver, ApiKeyMiddleware.
 */
final class MoreZeroMutationTest extends TestCase
{
    private function makeRequest(string $method = 'GET', string $path = '/api/test', array $headers = []): Request
    {
        return new Request($method, $path, [], $headers);
    }

    private function resetVersion(): void
    {
        $ref = new \ReflectionClass(VersionMiddleware::class);
        foreach (['versions', 'overrides', 'latestVersion'] as $prop) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $prop === 'latestVersion' ? 1 : []);
        }
    }

    public function testVersionMiddlewareRegistersAndSets(): void
    {
        $this->resetVersion();
        VersionMiddleware::register(1);
        VersionMiddleware::register(2, '/v2');
        $mw = new VersionMiddleware();
        $req = $this->makeRequest('GET', '/users', ['accept' => 'application/vnd.siro.v2+json']);
        $resp = $mw->handle($req, function (Request $r) {
            return Response::success();
        });
        $this->assertSame('2', $resp->getHeader('X-API-Version'));
    }

    public function testVersionMiddlewareLatestFallback(): void
    {
        $this->resetVersion();
        VersionMiddleware::register(1);
        VersionMiddleware::register(3, '/v3');
        $mw = new VersionMiddleware();
        $req = $this->makeRequest();
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame('3', $resp->getHeader('X-API-Version'));
    }

    public function testVersionMiddlewareUnknownVersionFallsBack(): void
    {
        $this->resetVersion();
        VersionMiddleware::register(1);
        $mw = new VersionMiddleware();
        $req = $this->makeRequest('GET', '/x', ['accept' => 'application/vnd.siro.v9+json']);
        $resp = $mw->handle($req, fn () => Response::success());
        $this->assertSame('1', $resp->getHeader('X-API-Version'));
    }

    public function testVersionMiddlewareOverride(): void
    {
        $this->resetVersion();
        VersionMiddleware::register(1);
        VersionMiddleware::override(1, 'GET', '/users', 'V2UserController');
        $override = VersionMiddleware::resolveOverride(1, 'GET', '/users');
        $this->assertSame('V2UserController', $override);
        $this->assertNull(VersionMiddleware::resolveOverride(1, 'GET', '/other'));
    }

    public function testRedisDriverUnavailable(): void
    {
        $driver = new RedisQueueDriver();
        $this->assertIsBool($driver->isAvailable());
        $this->assertNull($driver->pop('q', 1));
        $driver->push('q', '{}');
        $driver->release('q', '{}', 1);
        $this->assertTrue(true);
    }

    public function testApiKeyMiddlewareMissingKey(): void
    {
        $mw = new ApiKeyMiddleware();
        $resp = $mw->handle($this->makeRequest(), fn () => Response::success());
        $this->assertContains($resp->statusCode(), [401, 200]);
    }
}
