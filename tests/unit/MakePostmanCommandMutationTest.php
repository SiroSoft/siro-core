<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\MakePostmanCommand;
use Siro\Core\Env;

/**
 * Coverage tests for MakePostmanCommand.
 */
final class MakePostmanCommandMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_pm_' . uniqid('', true);
        $this->makeApp();
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

    private function makeApp(): void
    {
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'postman', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'public', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Resources', 0777, true);
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
    }

    private function writeRoutes(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\n\$router->get('/api/users', 'UserController@index');\n\$router->post('/api/users', 'UserController@store');\n\$router->get('/api/users/{id}', 'UserController@show');\n\$router->delete('/api/users/{id}', 'UserController@delete');\n\$router->post('/api/auth/login', 'AuthController@login');\n\$router->post('/api/auth/register', 'AuthController@register');\n\$router->get('/api/secure', 'UserController@index')->middleware('auth');\n\$router->post('/api/auth/refresh', 'AuthController@login')->middleware('auth');\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'UserController.php',
            "<?php\nclass UserController {\n    public function index(): Response { return response(); }\n    public function store(Request \$r): Response { \$r->validate(['name' => 'required|string|max:50', 'email' => 'required|email']); return response(); }\n    public function show(int \$id): Response { return response(); }\n    public function delete(int \$id): Response { return response(); }\n}\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'AuthController.php',
            "<?php\nclass AuthController {\n    public function login(Request \$r): Response { \$r->validate(['email' => 'required|email', 'password' => 'required|string']); return response(); }\n    public function register(Request \$r): Response { return response(); }\n}\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'UserResource.php',
            "<?php\nclass UserResource {\n    public function toArray(): array {\n        return ['id' => \$this->id, 'name' => \$this->name, 'email' => \$this->email];\n    }\n}\n"
        );
    }

    public function testGenerateCollection(): void
    {
        $this->writeRoutes();
        $cmd = new MakePostmanCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--host=localhost:9999']);
        ob_end_clean();
        $this->assertSame(0, $code);

        $file = $this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'postman' . DIRECTORY_SEPARATOR . 'collection.json';
        $this->assertFileExists($file);
        $json = json_decode((string) file_get_contents($file), true);
        $this->assertSame('Siro API', $json['info']['name']);
        $this->assertArrayHasKey('auth', $json);
        $this->assertNotEmpty($json['item']);
    }

    public function testGenerateWithFilters(): void
    {
        $this->writeRoutes();
        $cmd = new MakePostmanCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--tag=User', '--method=GET', '--path=/api/users', '--flow=crud']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'postman' . DIRECTORY_SEPARATOR . 'collection.json');
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'postman_collection.json');
    }

    public function testGenerateCustomOutput(): void
    {
        $this->writeRoutes();
        $out = $this->basePath . DIRECTORY_SEPARATOR . 'my_collection.json';
        $cmd = new MakePostmanCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--output=' . $out]);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($out);
    }

    public function testGenerateFlowAuth(): void
    {
        $this->writeRoutes();
        $cmd = new MakePostmanCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--flow=auth']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $json = json_decode((string) file_get_contents($this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'postman' . DIRECTORY_SEPARATOR . 'collection.json'), true);
        $this->assertArrayHasKey('event', $json);
    }
}
