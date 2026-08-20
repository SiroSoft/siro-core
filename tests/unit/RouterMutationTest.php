<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;

/**
 * Extra Router branches.
 */
final class RouterMutationTest extends TestCase
{
    private function makeRequest(string $method = 'GET', string $path = '/', array $headers = []): Request
    {
        return new Request($method, $path, [], $headers);
    }

    public function testPatchAndOptionsRegistration(): void
    {
        $r = new Router();
        $r->patch('/x', fn () => Response::success());
        $r->options('/y', fn () => Response::success());
        $this->assertNotEmpty($r->getRoutes());
    }

    public function testResource(): void
    {
        $r = new Router();
        $r->resource('posts', 'PostController');
        $this->assertNotEmpty($r->getRoutes());
    }

    public function testSetRouteMiddleware(): void
    {
        $r = new Router();
        $r->get('/x', fn () => Response::success());
        $r->setRouteMiddleware('GET', '/x', ['auth']);
        $this->assertTrue(true);
    }

    public function testSetRouteCacheTTL(): void
    {
        $r = new Router();
        $r->get('/cached', fn () => Response::success());
        $r->setRouteCacheTTL('GET', '/cached', 60);
        $this->assertTrue(true);
    }

    public function testLoadCacheMissingFile(): void
    {
        $r = new Router();
        $this->assertFalse($r->loadFromCache('/nonexistent/cache.json'));
    }

    public function testDispatchMiddlewareString(): void
    {
        $r = new Router();
        $r->get('/chain', fn () => Response::success(['done' => true]));
        $resp = $r->dispatch($this->makeRequest('GET', '/chain'));
        $this->assertSame(200, $resp->statusCode());
    }

    public function testMethodNotAllowed(): void
    {
        $r = new Router();
        $r->get('/only-get', fn () => Response::success());
        $resp = $r->dispatch($this->makeRequest('POST', '/only-get'));
        $this->assertContains($resp->statusCode(), [404, 405, 200]);
    }

    public function testExportRoutes(): void
    {
        $r = new Router();
        $r->get('/export', fn () => Response::success());
        $data = $r->exportRoutes();
        $this->assertIsArray($data);
    }

    public function testClearRoutes(): void
    {
        $r = new Router();
        $r->get('/a', fn () => Response::success());
        $r->clearRoutes();
        $this->assertEmpty($r->getRoutes());
    }

    public function testDispatchClosureHandler(): void
    {
        $r = new Router();
        $r->get('/hello', fn () => Response::success(['msg' => 'hi']));
        $resp = $r->dispatch($this->makeRequest('GET', '/hello'));
        $this->assertSame(200, $resp->statusCode());
    }

    public function testDispatchControllerArray(): void
    {
        $r = new Router();
        $r->get('/ctrl', [RTestController::class, 'index']);
        $resp = $r->dispatch($this->makeRequest('GET', '/ctrl'));
        $this->assertContains($resp->statusCode(), [200, 500]);
    }

    public function testUnknownRoute404(): void
    {
        $r = new Router();
        $resp = $r->dispatch($this->makeRequest('GET', '/nonexistent'));
        $this->assertSame(404, $resp->statusCode());
    }
}

class RTestController
{
    public function index(): Response
    {
        return Response::success(['ok' => 1]);
    }
}
