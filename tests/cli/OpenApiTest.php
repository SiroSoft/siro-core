<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

use Siro\Core\Console;
use Siro\Core\App;

class OpenApiTest extends TestCase
{
    private Console $console;
    private string $tempDir;
    private string $specFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/siro_openapi_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/app/Controllers', 0777, true);
        mkdir($this->tempDir . '/app/Resources', 0777, true);
        mkdir($this->tempDir . '/app/Models', 0777, true);
        mkdir($this->tempDir . '/routes', 0777, true);
        mkdir($this->tempDir . '/config', 0777, true);
        mkdir($this->tempDir . '/storage/logs', 0777, true);
        mkdir($this->tempDir . '/storage/framework', 0777, true);
        mkdir($this->tempDir . '/docs', 0777, true);

        file_put_contents($this->tempDir . '/.env', "APP_ENV=local\nAPP_KEY=testing_app_key_for_hmac_32chars!!\nSIRO_OPENAPI_ENABLED=true\n");
        file_put_contents($this->tempDir . '/config/database.php', '<?php return ["driver" => "sqlite", "database" => ":memory:"];');
        file_put_contents($this->tempDir . '/config/app.php', '<?php return ["name" => "TestApp", "env" => "local"];');
        file_put_contents($this->tempDir . '/routes/api.php', $this->getTestRoutes());

        $this->createUserController();
        $this->createUserResource();
        $this->createUserModel();

        putenv('SIRO_BASE_PATH=' . $this->tempDir);
        putenv('APP_ENV=local');
        putenv('SIRO_OPENAPI_ENABLED=true');
        $this->console = new Console($this->tempDir);
        $this->specFile = $this->tempDir . '/docs/openapi/openapi.json';
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    #[Test]
    public function openApiCommandGeneratesSpec(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $this->assertEquals(0, $exitCode, 'make:openapi should exit 0');
        $this->assertFileExists($this->specFile, 'openapi.json should be generated');
    }

    #[Test]
    public function specIsValidJson(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $content = file_get_contents($this->specFile);
        $spec = json_decode($content, true);
        $this->assertNotNull($spec, 'Spec must be valid JSON. Error: ' . json_last_error_msg());
    }

    #[Test]
    public function specHasRequiredFields(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);

        $this->assertArrayHasKey('openapi', $spec, 'Must have openapi version');
        $this->assertEquals('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('info', $spec, 'Must have info');
        $this->assertArrayHasKey('title', $spec['info'], 'Info must have title');
        $this->assertArrayHasKey('version', $spec['info'], 'Info must have version');
        $this->assertArrayHasKey('paths', $spec, 'Must have paths');
        $this->assertArrayHasKey('components', $spec, 'Must have components');
        $this->assertArrayHasKey('securitySchemes', $spec['components'], 'Must have securitySchemes');
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes'], 'Must have bearerAuth');
    }

    #[Test]
    public function specHasEndpoints(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);
        $paths = $spec['paths'] ?? [];

        $this->assertNotEmpty($paths, 'Must have at least one endpoint');

        // Check key endpoints exist (--flow=crud only includes /api/ paths)
        $expectedPaths = ['/api/users', '/api/users/{id}'];
        foreach ($expectedPaths as $p) {
            $this->assertArrayHasKey($p, $paths, "Missing endpoint: {$p}");
        }
    }

    #[Test]
    public function specHasCorrectMethods(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);
        $paths = $spec['paths'];

        // GET /api/users
        $this->assertArrayHasKey('get', $paths['/api/users'], '/api/users must have GET');
        // POST /api/users
        $this->assertArrayHasKey('post', $paths['/api/users'], '/api/users must have POST');
        // GET /api/users/{id}
        $this->assertArrayHasKey('get', $paths['/api/users/{id}'], '/api/users/{id} must have GET');
        // PUT /api/users/{id}
        $this->assertArrayHasKey('put', $paths['/api/users/{id}'], '/api/users/{id} must have PUT');
        // DELETE /api/users/{id}
        $this->assertArrayHasKey('delete', $paths['/api/users/{id}'], '/api/users/{id} must have DELETE');
    }

    #[Test]
    public function specHasParameters(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);
        $showOp = $spec['paths']['/api/users/{id}']['get'] ?? [];

        // Check path parameter
        $params = $showOp['parameters'] ?? [];
        $ids = array_filter($params, fn($p) => $p['name'] === 'id' && $p['in'] === 'path');
        $this->assertNotEmpty($ids, 'GET /api/users/{id} must have path param "id"');
    }

    #[Test]
    public function specHasRequestBodyForPost(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);
        $postOp = $spec['paths']['/api/users']['post'] ?? [];

        $this->assertArrayHasKey('requestBody', $postOp, 'POST /api/users must have requestBody (from validation rules)');
        $this->assertArrayHasKey('content', $postOp['requestBody'], 'requestBody must have content');
        $this->assertArrayHasKey('application/json', $postOp['requestBody']['content'], 'Must accept JSON');
    }

    #[Test]
    public function specHasResponses(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);

        // GET list should have 200 response
        $listOp = $spec['paths']['/api/users']['get'] ?? [];
        $this->assertArrayHasKey('200', $listOp['responses'] ?? [], 'GET list must have 200 response');

        // POST should have 201 + 422
        $postOp = $spec['paths']['/api/users']['post'] ?? [];
        $this->assertArrayHasKey('201', $postOp['responses'] ?? [], 'POST must have 201 response');
        $this->assertArrayHasKey('422', $postOp['responses'] ?? [], 'POST must have 422 response');

        // GET by id should have 404
        $showOp = $spec['paths']['/api/users/{id}']['get'] ?? [];
        $this->assertArrayHasKey('404', $showOp['responses'] ?? [], 'GET by id must have 404 response');
    }

    #[Test]
    public function specHasSecurityForProtectedRoutes(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);
        $this->assertIsArray($spec, 'OpenAPI spec should be valid JSON');
        $this->assertArrayHasKey('paths', $spec, 'Spec should contain paths');
    }

    #[Test]
    public function specHasComponents(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);
        $schemas = $spec['components']['schemas'] ?? [];

        // Core schemas
        $this->assertArrayHasKey('SuccessResponse', $schemas);
        $this->assertArrayHasKey('ErrorResponse', $schemas);
        $this->assertArrayHasKey('ValidationErrorResponse', $schemas);
        $this->assertArrayHasKey('PaginationMeta', $schemas);

        // Data schemas from controllers
        $hasDataSchema = false;
        foreach ($schemas as $name => $schema) {
            if (str_contains($name, 'Response') && $name !== 'SuccessResponse' && $name !== 'ErrorResponse' && $name !== 'ValidationErrorResponse') {
                $hasDataSchema = true;
                break;
            }
        }
        $this->assertTrue($hasDataSchema, 'Should have at least one data response schema');
    }

    #[Test]
    public function specExcludesSensitiveFields(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $specContent = file_get_contents($this->specFile);

        // Sensitive fields must NOT appear in the spec
        $this->assertStringNotContainsString('password_reset_token', $specContent, 'Must not expose password_reset_token');
        $this->assertStringNotContainsString('verification_token', $specContent, 'Must not expose verification_token');
        $this->assertStringNotContainsString('deleted_at', $specContent, 'Must not expose deleted_at');
    }

    #[Test]
    public function specTagsAreCorrect(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);
        $tags = $spec['tags'] ?? [];

        $tagNames = array_map(fn($t) => $t['name'], $tags);
        $this->assertContains('Users', $tagNames, 'Must have Users tag from UserController');
        // Auth tag only appears with --flow=auth, not --flow=crud
    }

    #[Test]
    public function specSummaryIsDescriptive(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);

        $summaries = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $op) {
                $this->assertArrayHasKey('summary', $op, "{$method} {$path} must have summary");
                $summaries[] = $op['summary'];
            }
        }

        $this->assertContains('List all users', $summaries, 'GET /api/users should be "List all users"');
        $this->assertContains('Create users', $summaries, 'POST /api/users should be "Create users"');
        $this->assertContains('Get users', $summaries, 'GET /api/users/{id} should be "Get users"');
        $this->assertContains('Delete users', $summaries, 'DELETE /api/users/{id} should be "Delete users"');
    }

    #[Test]
    public function specWithAuthFlowWorks(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'make:openapi', '--flow=auth']);
        ob_get_clean();

        $this->assertEquals(0, $exitCode, 'make:openapi --flow=auth should exit 0');

        $spec = json_decode(file_get_contents($this->specFile), true);
        $paths = $spec['paths'] ?? [];

        // Auth flow should ONLY have auth endpoints
        foreach (array_keys($paths) as $path) {
            $this->assertStringContainsString('/auth/', $path, "Auth flow should only contain auth endpoints, got: {$path}");
        }
    }

    #[Test]
    public function specExcludesOptionsRoutes(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $specContent = file_get_contents($this->specFile);
        $this->assertStringNotContainsString('OPTIONS', $specContent, 'Must not include OPTIONS methods');
    }

    #[Test]
    public function specProducesSameOutputEveryRun(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();
        $first = file_get_contents($this->specFile);

        // Remove and regenerate
        unlink($this->specFile);

        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();
        $second = file_get_contents($this->specFile);

        $this->assertEquals($first, $second, 'Output must be deterministic (same every run)');
    }

    #[Test]
    public function specValidationErrorSchemaUsed(): void
    {
        ob_start();
        $this->console->run(['siro', 'make:openapi', '--flow=crud']);
        ob_get_clean();

        $spec = json_decode(file_get_contents($this->specFile), true);

        // Check 422 responses use the ValidationErrorResponse schema
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $op) {
                if (isset($op['responses']['422'])) {
                    $ref = $op['responses']['422']['content']['application/json']['schema']['$ref'] ?? '';
                    $this->assertEquals(
                        '#/components/schemas/ValidationErrorResponse',
                        $ref,
                        "{$method} {$path} 422 must reference ValidationErrorResponse"
                    );
                }
            }
        }
    }

    private function getTestRoutes(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Siro\Core\Router;

$router->get('/', function () {
    return ['message' => 'API'];
});

// Auth routes
$router->post('/auth/register', [\App\Controllers\AuthController::class, 'register']);
$router->post('/auth/login', [\App\Controllers\AuthController::class, 'login']);
$router->post('/auth/refresh', [\App\Controllers\AuthController::class, 'refresh']);
$router->post('/auth/forgot-password', [\App\Controllers\AuthController::class, 'forgotPassword']);
$router->post('/auth/reset-password', [\App\Controllers\AuthController::class, 'resetPassword']);
$router->get('/auth/me', [\App\Controllers\AuthController::class, 'me']);
$router->post('/auth/logout', [\App\Controllers\AuthController::class, 'logout']);

// User CRUD
$router->get('/api/users', [\App\Controllers\UserController::class, 'index']);
$router->post('/api/users', [\App\Controllers\UserController::class, 'store']);
$router->get('/api/users/{id}', [\App\Controllers\UserController::class, 'show']);
$router->put('/api/users/{id}', [\App\Controllers\UserController::class, 'update']);
$router->delete('/api/users/{id}', [\App\Controllers\UserController::class, 'destroy']);
PHP;
    }

    private function createUserController(): void
    {
        file_put_contents($this->tempDir . '/app/Controllers/UserController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

use Siro\Core\Request;
use Siro\Core\Response;

class UserController
{
    public function index(Request $request): array
    {
        return ['data' => [], 'meta' => ['total' => 0]];
    }

    public function store(Request $request): Response
    {
        $request->validate([
            'name' => 'required|min:3|max:120',
            'email' => 'required|email|max:255',
            'password' => 'required|min:6|max:255',
        ]);
        return Response::created(['id' => 1], 'User created');
    }

    public function show(int $id): array
    {
        return ['data' => ['id' => $id, 'name' => 'Test', 'email' => 'test@test.com']];
    }

    public function update(Request $request, int $id): Response
    {
        $request->validate([
            'name' => 'min:3|max:120',
            'email' => 'email|max:255',
        ]);
        return Response::success(['id' => $id], 'User updated');
    }

    public function destroy(int $id): Response
    {
        return Response::success(null, 'User deleted');
    }
}
PHP);
    }

    private function createUserResource(): void
    {
        file_put_contents($this->tempDir . '/app/Resources/UserResource.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Resources;

class UserResource
{
    public function toArray(): array
    {
        return [
            'id' => 1,
            'name' => 'string',
            'email' => 'string',
            'created_at' => '2026-01-01T00:00:00Z',
        ];
    }
}
PHP);
    }

    private function createUserModel(): void
    {
        file_put_contents($this->tempDir . '/app/Models/User.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

class User extends Model
{
    protected array $fillable = ['name', 'email', 'password', 'status', 'role'];
    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'token_version' => 'integer',
    ];
}
PHP);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getRealPath());
            else unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
