<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\MakeOpenApiCommand;
use Siro\Core\Env;

/**
 * Coverage tests for MakeOpenApiCommand (spec generation from fake app).
 */
final class MakeOpenApiCommandMutationTest extends TestCase
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
        putenv('SIRO_OPENAPI_ENABLED');
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_oas_' . uniqid('', true);
        $this->makeApp();
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
        Cache::reset();
        putenv('APP_ENV');
        putenv('SIRO_OPENAPI_ENABLED');
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
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'openapi', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'public', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Resources', 0777, true);
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . '.env',
            "APP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234\nSIRO_OPENAPI_ENABLED=true\n"
        );
    }

    private function writeRoutes(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            "<?php\n\$router->get('/api/users', 'UserController@index');\n\$router->post('/api/users', 'UserController@store');\n\$router->get('/api/users/{id}', 'UserController@show');\n\$router->put('/api/users/{id}', 'UserController@update');\n\$router->delete('/api/users/{id}', 'UserController@delete');\n\$router->post('/api/auth/login', 'AuthController@login');\n\$router->get('/health', 'HealthController@ready');\n"
        );
    }

    private function writeControllers(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'UserController.php',
            "<?php\nclass UserController {\n    public function index(Request \$r) { \$r->validate(['page' => 'integer']); }\n    public function store(Request \$r) { \$r->validate(['name' => 'required|string|min:2|max:50', 'email' => 'required|email']); }\n    public function show(Request \$r, int \$id) {}\n    public function update(Request \$r, int \$id) { \$r->validate(['name' => 'string']); }\n    public function delete(Request \$r, int \$id) {}\n}\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'AuthController.php',
            "<?php\nclass AuthController {\n    public function login(Request \$r) { \$r->validate(['email' => 'required|email', 'password' => 'required|string']); }\n}\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'HealthController.php',
            "<?php\nclass HealthController {\n    public function ready() { return 'ok'; }\n}\n"
        );
    }

    private function writeModelAndResource(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'User.php',
            "<?php\nclass User {\n    protected \$casts = ['id' => 'int', 'is_active' => 'bool', 'balance' => 'float'];\n    protected \$fillable = ['name', 'email'];\n}\n"
        );
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'UserResource.php',
            "<?php\nclass UserResource {\n    public function toArray(): array {\n        return ['id' => \$this->id, 'name' => \$this->name, 'email' => \$this->email];\n    }\n}\n"
        );
    }

    public function testDisabledInProduction(): void
    {
        putenv('APP_ENV=production');
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env', "APP_ENV=production\n");
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testForceOverridesProduction(): void
    {
        putenv('APP_ENV=production');
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . '.env', "APP_ENV=production\nSIRO_OPENAPI_ENABLED=true\n");
        $this->writeRoutes();
        $this->writeControllers();
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'openapi' . DIRECTORY_SEPARATOR . 'openapi.json');
    }



    public function testGenerateWithOutputFile(): void
    {
        $this->writeRoutes();
        $this->writeControllers();
        $out = $this->basePath . DIRECTORY_SEPARATOR . 'custom.json';
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--output=' . $out, '--with-swagger']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($out);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'openapi.json');
    }

    public function testFallbackRoutesMode(): void
    {
        // No routes/api.php -> fallback parses controllers
        $this->writeControllers();
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'openapi' . DIRECTORY_SEPARATOR . 'openapi.json');
    }

    public function testOpenApiEnabledViaEnv(): void
    {
        putenv('SIRO_OPENAPI_ENABLED=1');
        $this->writeRoutes();
        $this->writeControllers();
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }
}
