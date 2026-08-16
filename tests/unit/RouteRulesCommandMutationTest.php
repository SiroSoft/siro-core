<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\RouteRulesCommand;
use Siro\Core\Env;

/**
 * Coverage tests for RouteRulesCommand.
 */
final class RouteRulesCommandMutationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_rr_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers', 0777, true);
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
        Cache::reset();
        putenv('APP_ENV');
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        parent::tearDown();
    }

    private function rmDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testNoRoutesFile(): void
    {
        $cmd = new RouteRulesCommand($this->basePath);
        $code = $cmd->run([]);
        $this->assertSame(1, $code);
    }

    public function testInlineValidateRules(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\nRoute::get('/users', [UserController::class, 'index']);\nRoute::post('/users', function () {\n    \$request->validate(['name' => 'required|string', 'email' => 'required|email']);\n});\n"
        );

        $cmd = new RouteRulesCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Route Validation Rules', (string) $out);
        $this->assertStringContainsString('name', (string) $out);
    }

    public function testControllerRules(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\nRoute::post('/orders', [OrderController::class, 'store']);\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'OrderController.php',
            "<?php\nclass OrderController {\n    public function store(Request \$r) {\n        \$r->validate(['total' => 'required|numeric', 'note' => 'nullable|string']);\n    }\n}\n"
        );

        $cmd = new RouteRulesCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('total', (string) $out);
        $this->assertStringContainsString('note', (string) $out);
    }

    public function testGroupRouteDetected(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\n\$router->group('/admin', function () {\n    \$request->validate(['role' => 'required']);\n});\n"
        );

        $cmd = new RouteRulesCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('GROUP', (string) $out);
    }

    public function testNoRulesFound(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\nRoute::get('/ping', fn () => 'pong');\n"
        );

        $cmd = new RouteRulesCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('No validation rules', (string) $out);
    }
}
