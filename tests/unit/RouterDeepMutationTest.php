<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Middleware\JsonMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;

/**
 * Router deep branches: real middleware, version, resource cache, options.
 */
final class RouterDeepMutationTest extends TestCase
{
    private function makeRequest(string $method = 'GET', string $path = '/', array $headers = []): Request
    {
        return new Request($method, $path, [], $headers);
    }

    public function testDispatchWithJsonMiddleware(): void
    {
        $r = new Router();
        $r->get('/json', fn () => Response::success(), [JsonMiddleware::class]);
        $resp = $r->dispatch($this->makeRequest('GET', '/json'));
        $this->assertSame(200, $resp->statusCode());
    }

    public function testResourceWithCacheTtl(): void
    {
        $r = new Router();
        $r->resource('items', 'ItemController', [], 60);
        $this->assertNotEmpty($r->getRoutes());
    }

    public function testVersionRoutes(): void
    {
        $r = new Router();
        $r->version(2, function ($router) {
            $router->get('/v2/status', fn () => Response::success(['v' => 2]));
        });
        $this->assertNotEmpty($r->getRoutes());
    }

    public function testGroupMiddleware(): void
    {
        $r = new Router();
        $r->group('/api', [JsonMiddleware::class], function ($router) {
            $router->get('/x', fn () => Response::success());
        });
        $this->assertNotEmpty($r->getRoutes());
    }

    public function testOptionsRequest405(): void
    {
        $r = new Router();
        $r->get('/opt', fn () => Response::success());
        $resp = $r->dispatch($this->makeRequest('OPTIONS', '/opt'));
        $this->assertContains($resp->statusCode(), [200, 204, 405]);
    }

    public function testDispatchClosureWithRequestArg(): void
    {
        $r = new Router();
        $r->get('/cr', fn (Request $req) => Response::success(['path' => $req->path()]));
        $resp = $r->dispatch($this->makeRequest('GET', '/cr'));
        $this->assertSame(200, $resp->statusCode());
    }

    public function testStringResultThrows(): void
    {
        $r = new Router();
        $r->get('/str', fn () => 'plain string');
        try {
            $r->dispatch($this->makeRequest('GET', '/str'));
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('result', $e->getMessage());
        }
    }

    public function testDispatchArrayResult(): void
    {
        $r = new Router();
        $r->get('/arr', fn () => ['ok' => 1]);
        $resp = $r->dispatch($this->makeRequest('GET', '/arr'));
        $this->assertSame(200, $resp->statusCode());
    }
}
