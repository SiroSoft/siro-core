<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Gate;
use Siro\Core\Request;
use Siro\Core\Route;
use Siro\Core\Router;
use Siro\Core\Tests\TestCase;

/**
 * Controller attributes: Middleware/Authorize/Throttle/CacheResponse
 * wire-up through Route::registerAttributes().
 */
final class AttributesTest extends TestCase
{
    private string $tempDir;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::reset();
        $this->tempDir = sys_get_temp_dir() . '/siro_attrs_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);

        file_put_contents($this->tempDir . '/WidgetController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use Siro\Core\Attributes\Authorize;
use Siro\Core\Attributes\Body;
use Siro\Core\Attributes\CacheResponse;
use Siro\Core\Attributes\Middleware;
use Siro\Core\Attributes\QueryParam;
use Siro\Core\Attributes\Throttle;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\RouteAttribute;

#[Middleware('auth')]
class WidgetController
{
    #[RouteAttribute('/api/widgets', method: ['GET'])]
    #[CacheResponse(120)]
    #[QueryParam('search', required: false, description: 'Filter text')]
    public function index(Request $request): Response
    {
        return Response::success(['ok' => true]);
    }

    #[RouteAttribute('/api/widgets', method: ['POST'])]
    #[Authorize('widgets.create')]
    #[Throttle(10, minutes: 1)]
    #[Body('name', rules: 'required|string', description: 'Widget name')]
    public function store(Request $request): Response
    {
        return Response::created(['id' => 1]);
    }
}
PHP
        );

        spl_autoload_register(function (string $class): void {
            if ($class === 'App\\Controllers\\WidgetController') {
                require $this->tempDir . '/WidgetController.php';
            }
        }, true, true);

        // Stand-in for the app-registered 'auth' alias (wire-up test, not auth logic).
        Router::registerMiddlewareAlias('auth', \Siro\Core\Middleware\CorsMiddleware::class);
        $this->router = new Router();
        Route::setRouter($this->router);
        Route::registerAttributes($this->tempDir);
    }

    protected function tearDown(): void
    {
        Gate::reset();
        Route::clearRoutes();
        Router::resetStatic();
        $this->rrmdir($this->tempDir);
        parent::tearDown();
    }

    public function testClassMiddlewareApplied(): void
    {
        $routes = $this->router->getRoutes();
        foreach ($routes as $route) {
            $this->assertStringContainsString('auth', $route['middleware']);
        }
    }

    public function testMethodThrottleDesugared(): void
    {
        $routes = $this->router->getRoutes();
        $store = null;
        foreach ($routes as $route) {
            if (str_contains($route['handler'], 'store')) {
                $store = $route;
            }
        }
        $this->assertNotNull($store);
        $this->assertStringContainsString('ThrottleMiddleware:10,1', $store['middleware']);
    }

    public function testAuthorizeEnforcedAtDispatch(): void
    {
        // Router lets AuthorizationException bubble (App::run maps it to 403,
        // same as ValidationException). The 403 mapping itself is covered in GateTest.
        $request = new Request('POST', '/api/widgets', [], ['content-type' => 'application/json'], ['name' => 'W']);
        $request->setUser(['role' => 'viewer']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected AuthorizationException');
        } catch (\Siro\Core\AuthorizationException $e) {
            $this->assertSame(403, $e->getCode());
        }

        Gate::define('widgets.create', fn (?array $user): bool => ($user['role'] ?? '') === 'admin');
        $request->setUser(['role' => 'admin']);
        $response = $this->router->dispatch($request);
        $this->assertSame(201, $response->statusCode());
    }

    public function testCacheTtlFromAttribute(): void
    {
        $routes = $this->router->getRoutes();
        $index = null;
        foreach ($routes as $route) {
            if (str_contains($route['handler'], 'index')) {
                $index = $route;
            }
        }
        $this->assertNotNull($index);
        $this->assertSame(120, $index['cache_ttl']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }
}
