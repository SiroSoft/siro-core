<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Cache;
use Siro\Core\Env;

/**
 * App extra branches: locale, maintenance, providers, debug.
 */
final class AppBranchMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_appb_' . uniqid();
        mkdir($this->basePath . '/routes', 0777, true);
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/storage/framework', 0777, true);
        mkdir($this->basePath . '/lang', 0777, true);
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

    private function runWithServer(array $server): string
    {
        file_put_contents($this->basePath . '/routes/api.php', "<?php\n\$router->get('/', function () { return ['ok' => true]; });\n");
        $app = new App($this->basePath);
        $app->boot();
        $app->loadRoutes($this->basePath . '/routes/api.php');
        $orig = $_SERVER;
        $_SERVER = array_merge($orig, $server);
        ob_start();
        $app->run();
        $out = ob_get_clean();
        $_SERVER = $orig;
        return $out ?: '';
    }

    public function testRunWithLocaleHeader(): void
    {
        $out = $this->runWithServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_LOCALE' => 'vi']);
        $this->assertNotEmpty($out);
    }

    public function testRunWithAcceptLanguage(): void
    {
        $out = $this->runWithServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        $this->assertNotEmpty($out);
    }

    public function testRunMaintenanceMode(): void
    {
        file_put_contents($this->basePath . '/storage/framework/down', json_encode(['retry' => 60]));
        $out = $this->runWithServer(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);
        $this->assertNotEmpty($out);
        @unlink($this->basePath . '/storage/framework/down');
    }

    public function testIsDownWithoutFile(): void
    {
        $this->assertNull(App::isDown());
    }

    public function testIsDownWithFile(): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/framework';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/down', json_encode(['allow' => ['127.0.0.1']]));
        $down = App::isDown();
        $this->assertIsArray($down);
        @unlink($dir . '/down');
    }

    public function testBootWithProviders(): void
    {
        // vendors/composer/installed.json triggers provider discovery
        mkdir($this->basePath . '/vendor/composer', 0777, true);
        file_put_contents($this->basePath . '/vendor/composer/installed.json', json_encode(['packages' => []]));
        $app = new App($this->basePath);
        $app->boot();
        $this->assertInstanceOf(\Siro\Core\Router::class, $app->router());
    }
}
