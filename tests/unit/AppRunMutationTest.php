<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Cache;
use Siro\Core\Env;

/**
 * App::run() edge paths: maintenance mode, locale, providers.
 */
final class AppRunMutationTest extends TestCase
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
        putenv('APP_DEBUG=true');
        putenv('APP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678');
        putenv('JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234');
        $this->basePath = sys_get_temp_dir() . '/siro_apprun2_' . uniqid();
        mkdir($this->basePath . '/routes', 0777, true);
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        file_put_contents(
            $this->basePath . '/.env',
            "APP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234\n"
        );
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testRunHealthRoute(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', "<?php\n\$router->get('/health', function () { return ['ok' => true]; });\n");
        $app = new App($this->basePath);
        $app->boot();
        $app->loadRoutes($this->basePath . '/routes/api.php');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/health';
        ob_start();
        $app->run();
        $out = ob_get_clean();
        $this->assertNotEmpty($out);
    }

    public function testRunTraceparent(): void
    {
        file_put_contents($this->basePath . '/routes/api.php', "<?php\n\$router->get('/', function () { return ['ok' => true]; });\n");
        $app = new App($this->basePath);
        $app->boot();
        $app->loadRoutes($this->basePath . '/routes/api.php');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_TRACEPARENT'] = '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01';
        ob_start();
        $app->run();
        $out = ob_get_clean();
        $this->assertNotEmpty($out);
    }

    public function testRunUnknownRoute(): void
    {
        $app = new App($this->basePath);
        $app->boot();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/nonexistent-path';
        ob_start();
        $app->run();
        $out = ob_get_clean();
        $this->assertNotEmpty($out);
    }

    public function testRouterInstance(): void
    {
        $app = new App($this->basePath);
        $app->boot();
        $this->assertInstanceOf(\Siro\Core\Router::class, $app->router());
    }
}
