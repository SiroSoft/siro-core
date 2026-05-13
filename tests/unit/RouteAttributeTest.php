<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Route;
use Siro\Core\RouteAttribute;
use Siro\Core\Router;

class RouteAttributeTest extends TestCase
{
    private string $tempDir;
    private Router $router;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/siro_attr_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);

        file_put_contents($this->tempDir . '/ProductController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use Siro\Core\RouteAttribute;
use Siro\Core\Request;
use Siro\Core\Response;

class ProductController
{
    #[RouteAttribute("/api/products", method: ["GET"])]
    public function index(Request $request): array
    {
        return ["data" => []];
    }

    #[RouteAttribute("/api/products", method: "POST", middleware: ["auth"])]
    public function store(Request $request): Response
    {
        return Response::created(["id" => 1], "Created");
    }

    #[RouteAttribute("/api/products/{id}", method: "GET")]
    public function show(int $id): array
    {
        return ["data" => ["id" => $id]];
    }

    #[RouteAttribute("/api/products/{id}", method: "PUT", middleware: ["auth"])]
    public function update(int $id): Response
    {
        return Response::success(null, "Updated");
    }

    #[RouteAttribute("/api/products/{id}", method: "DELETE", middleware: ["auth"])]
    public function destroy(int $id): Response
    {
        return Response::success(null, "Deleted");
    }
}
PHP
        );

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'App\\Controllers\\')) {
                $name = substr($class, strlen('App\\Controllers\\'));
                $file = $this->tempDir . '/' . $name . '.php';
                if (file_exists($file)) {
                    require $file;
                }
            }
        }, true, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    public function testRegisterAttributesCreatesRoutes(): void
    {
        Route::setRouter(new Router());
        Route::registerAttributes($this->tempDir);

        $this->assertNotNull(Route::getRouter(), 'Router should be set');
        $routes = Route::getRouter()->getRoutes();
        $this->assertNotEmpty($routes, 'Routes should be registered from attributes');
    }

    public function testAllAttributeRoutesAreRegistered(): void
    {
        $router = new Router();
        Route::setRouter($router);
        Route::registerAttributes($this->tempDir);

        $routes = $router->getRoutes();
        $routePaths = array_map(fn($r) => $r['method'] . ' ' . $r['path'], $routes);

        $expected = ['GET /api/products', 'POST /api/products', 'GET /api/products/{id}', 'PUT /api/products/{id}', 'DELETE /api/products/{id}'];
        foreach ($expected as $e) {
            $this->assertContains($e, $routePaths, "Missing route: {$e}");
        }
    }

    public function testControllerRouteMatches(): void
    {
        $router = new Router();
        Route::setRouter($router);
        Route::registerAttributes($this->tempDir);

        $req = new \Siro\Core\Request('GET', '/api/products');
        $resp = $router->dispatch($req);
        $this->assertEquals(200, $resp->statusCode());
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
