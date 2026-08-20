<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\MakeOpenApiCommand;
use Siro\Core\Env;

/**
 * MakeOpenApi deep branches: pluralization, where-constraints, enum rules.
 */
final class MakeOpenApiMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_oas2_' . uniqid();
        mkdir($this->basePath . '/routes', 0777, true);
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/docs/openapi', 0777, true);
        mkdir($this->basePath . '/public', 0777, true);
        mkdir($this->basePath . '/app/Controllers', 0777, true);
        mkdir($this->basePath . '/app/Models', 0777, true);
        mkdir($this->basePath . '/app/Resources', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        file_put_contents(
            $this->basePath . '/.env',
            "APP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=this_is_a_sufficiently_long_app_key_for_tests_12345678\nJWT_SECRET=this_is_a_sufficiently_long_jwt_secret_for_tests_1234\nSIRO_OPENAPI_ENABLED=true\n"
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

    private function setupRoutesAndControllers(): void
    {
        file_put_contents(
            $this->basePath . '/routes/api.php',
            "<?php\n" .
            "\$router->get('/api/categories', 'CategoryController@index');\n" .
            "\$router->get('/api/boxes/{id}', 'BoxController@show');\n" .
            "\$router->get('/api/statuses/{id}', 'StatusController@show');\n" .
            "\$router->get('/api/branches/{id}', 'BranchController@show');\n" .
            "\$router->get('/api/watch/{id}', 'WatchController@show');\n" .
            "\$router->post('/api/orders', 'OrderController@store');\n" .
            "\$router->get('/api/users/{id}', 'UserController@show')->where('id', '[0-9]+');\n" .
            "\$router->get('/api/products/{uuid}', 'ProductController@show')->where('uuid', '[a-fA-F0-9-]+');\n" .
            "\$router->get('/api/search/{q}', 'SearchController@search')->where('q', '[a-zA-Z0-9_-]+');\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/CategoryController.php',
            "<?php\nclass CategoryController {\n    public function index() {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/BoxController.php',
            "<?php\nclass BoxController {\n    public function show() {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/StatusController.php',
            "<?php\nclass StatusController {\n    public function show() {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/BranchController.php',
            "<?php\nclass BranchController {\n    public function show() {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/WatchController.php',
            "<?php\nclass WatchController {\n    public function show() {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/OrderController.php',
            "<?php\nclass OrderController {\n    public function store(Request \$r) { \$r->validate(['status' => 'required|in:pending,paid,shipped', 'email' => 'required|email', 'count' => 'integer']); }\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/UserController.php',
            "<?php\nclass UserController {\n    public function show(Request \$r, int \$id) {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/ProductController.php',
            "<?php\nclass ProductController {\n    public function show() {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/SearchController.php',
            "<?php\nclass SearchController {\n    public function search() {}\n}\n"
        );
    }

    public function testGenerateWithPluralizationAndConstraints(): void
    {
        $this->setupRoutesAndControllers();
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force']);
        ob_end_clean();
        $this->assertSame(0, $code);

        $file = $this->basePath . '/docs/openapi/openapi.json';
        $this->assertFileExists($file);
        $json = json_decode((string) file_get_contents($file), true);
        $this->assertArrayHasKey('/api/orders', $json['paths']);
        $this->assertArrayHasKey('/api/categories', $json['paths']);
    }

    public function testGenerateWithEnumRules(): void
    {
        $this->setupRoutesAndControllers();
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force']);
        ob_end_clean();
        $this->assertSame(0, $code);

        $json = json_decode((string) file_get_contents($this->basePath . '/docs/openapi/openapi.json'), true);
        // OrderStoreRequest schema should include enum for status
        $schemas = $json['components']['schemas'] ?? [];
        $found = false;
        foreach ($schemas as $schema) {
            if (isset($schema['properties']['status']['enum'])) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    private function setupResourceAndModel(): void
    {
        file_put_contents(
            $this->basePath . '/routes/api.php',
            "<?php\n" .
            "\$router->get('/api/users/{id}', 'UserController@show');\n" .
            "\$router->get('/api/auth/me', 'AuthController@me');\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/UserController.php',
            "<?php\nclass UserController {\n    public function show(Request \$r, int \$id) {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/AuthController.php',
            "<?php\nclass AuthController {\n    public function me() {}\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Models/User.php',
            "<?php\nclass User {\n    protected \$casts = ['id' => 'int', 'balance' => 'float', 'is_active' => 'bool', 'meta' => 'json'];\n    protected \$fillable = ['name', 'email'];\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Resources/UserResource.php',
            "<?php\nclass UserResource {\n    public function toArray(): array {\n        return ['id' => \$this->id, 'email' => \$this->email, 'balance' => \$this->balance];\n    }\n}\n"
        );
        file_put_contents(
            $this->basePath . '/app/Http/Requests/StoreUserRequest.php',
            "<?php\nclass StoreUserRequest {\n    public function rules(): array {\n        return ['name' => 'required|string|max:50', 'email' => 'required|email'];\n    }\n}\n"
        );
    }

    public function testResourceAndModelParsing(): void
    {
        $this->setupResourceAndModel();
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force']);
        ob_end_clean();
        $this->assertSame(0, $code);

        $json = json_decode((string) file_get_contents($this->basePath . '/docs/openapi/openapi.json'), true);
        $this->assertArrayHasKey('/api/auth/me', $json['paths']);
    }

    public function testCopyToPublic(): void
    {
        $this->setupResourceAndModel();
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force', '--with-swagger']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . '/public/openapi.json');
    }

    public function testFormRequestValidation(): void
    {
        $this->setupResourceAndModel();
        // route with FormRequest type-hinted controller
        file_put_contents(
            $this->basePath . '/routes/api.php',
            "<?php\n\$router->post('/api/users', 'UserController@store');\n"
        );
        file_put_contents(
            $this->basePath . '/app/Controllers/UserController.php',
            "<?php\nclass UserController {\n    public function store(StoreUserRequest \$request) {}\n}\n"
        );
        $cmd = new MakeOpenApiCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--force']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }
}
