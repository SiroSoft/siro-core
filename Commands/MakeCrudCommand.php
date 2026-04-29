<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeCrudCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $resource = strtolower(trim((string) ($args[0] ?? '')));
        if ($resource === '') {
            $this->write('Resource name is required. Example: php siro make:crud users');
            $this->write('This generates: Model, Migration, Controller, Resource, Routes, and Test file.');
            return 1;
        }

        $resource = preg_replace('/[^a-z0-9_]+/', '_', $resource) ?? $resource;
        $resource = trim($resource, '_');
        if ($resource === '') {
            $this->write('Invalid resource name.');
            return 1;
        }

        $model = ucfirst($this->studly($this->singular($resource)));
        $controllerClass = $model . 'Controller';
        $resourceClass = $model . 'Resource';
        $table = $this->plural(strtolower($resource));

        $this->write("Generating CRUD for: {$resource}");
        $this->write('');

        $ok = true;

        // 1. Model
        $ok = $this->generateModel($model, $table) && $ok;

        // 2. Migration
        $ok = $this->generateMigration($table, $model) && $ok;

        // 3. Controller
        $ok = $this->generateController($controllerClass, $model, $resource) && $ok;

        // 4. Resource
        $ok = $this->generateResource($resourceClass) && $ok;

        // 5. Routes
        $ok = $this->generateRoutes($resource, $controllerClass) && $ok;

        // 6. Test
        $ok = $this->generateTest($resource, $model) && $ok;

        $this->write('');
        if ($ok) {
            $this->write('CRUD generation complete. Next steps:');
            $this->write("  php siro migrate");
            $this->write("  php siro db:seed");
            $this->write("  php siro api:test GET /api/{$resource}");
        } else {
            $this->write('Some files were skipped (already exist). Use --force to overwrite.');
        }

        return $ok ? 0 : 1;
    }

    private function generateModel(string $model, string $table): bool
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $model . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Models/' . $model . '.php');
            return false;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class {$model} extends Model
{
    protected string \$table = '{$table}';

    protected array \$hidden = [];

    protected array \$casts = [
        'id' => 'int',
    ];

    protected array \$fillable = [
        'name',
    ];
}

PHP);
        $this->write('Generated: app/Models/' . $model . '.php');
        return true;
    }

    private function generateMigration(string $table, string $model): bool
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $timestamp = date('YmdHis');
        $filename = $timestamp . '_create_' . $table . '_table.php';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $columns = $this->detectColumns($model);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

return new class {
    public function up(\PDO \$db): void
    {
        \$db->exec("CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            {$columns}
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=INNODB DEFAULT CHARSET=utf8mb4");
    }

    public function down(\PDO \$db): void
    {
        \$db->exec("DROP TABLE IF EXISTS {$table}");
    }
};

PHP);
        $this->write('Generated: database/migrations/' . $filename);
        $this->write('  Run: php siro migrate');
        return true;
    }

    private function detectColumns(string $model): string
    {
        return '';
    }

    private function generateController(string $class, string $model, string $resource): bool
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Controllers/' . $class . '.php');
            return false;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\{$model};
use Siro\Core\Request;
use Siro\Core\Response;

final class {$class}
{
    public function index(Request \$request): Response
    {
        \$perPage = \$request->queryInt('per_page', 20);
        \$page = \$request->queryInt('page', 1);

        \$result = {$model}::query()
            ->orderBy('id', 'DESC')
            ->paginate(\$perPage, \$page);

        return Response::paginated(\$result['data'], \$result['meta'], '{$resource} list fetched');
    }

    public function show(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) {
            return Response::error('Invalid id', 422);
        }

        \$item = {$model}::find(\$id);
        if (\$item === null) {
            return Response::error('{$model} not found', 404);
        }

        return Response::success(\$item->toArray(), '{$model} fetched');
    }

    public function store(Request \$request): Response
    {
        \$validated = \$request->validate([
            'name' => 'required|min:3|max:120',
        ]);

        \$item = {$model}::create([
            'name' => \$validated['name'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return Response::created(\$item->toArray(), '{$model} created');
    }

    public function update(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) {
            return Response::error('Invalid id', 422);
        }

        \$item = {$model}::find(\$id);
        if (\$item === null) {
            return Response::error('{$model} not found', 404);
        }

        \$validated = \$request->validate([
            'name' => 'min:3|max:120',
        ]);

        \$item->update(\$validated);
        return Response::success(\$item->toArray(), '{$model} updated');
    }

    public function delete(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) {
            return Response::error('Invalid id', 422);
        }

        \$item = {$model}::find(\$id);
        if (\$item === null) {
            return Response::error('{$model} not found', 404);
        }

        \$item->delete();
        return Response::success(null, '{$model} deleted');
    }
}

PHP);
        $this->write('Generated: app/Controllers/' . $class . '.php');
        return true;
    }

    private function generateResource(string $class): bool
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Resources/' . $class . '.php');
            return false;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class {$class} extends Resource
{
    public function toArray(): array
    {
        return [
            'id' => \$this->data['id'] ?? null,
            'name' => \$this->data['name'] ?? null,
            'created_at' => \$this->data['created_at'] ?? null,
        ];
    }
}

PHP);
        $this->write('Generated: app/Resources/' . $class . '.php');
        return true;
    }

    private function generateRoutes(string $resource, string $controllerClass): bool
    {
        $routeFile = $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php';
        $marker = "make:crud {$resource}";

        if (!is_file($routeFile)) {
            file_put_contents($routeFile, "<?php\n\ndeclare(strict_types=1);\n\n");
            $content = (string) file_get_contents($routeFile);
        } else {
            $content = (string) file_get_contents($routeFile);
        }

        if (str_contains($content, $marker)) {
            $this->write('Routes already exist for ' . $resource . '. Skipped.');
            return false;
        }

        $routes = <<<PHP
    // Generated by: php siro make:crud {$resource}
    \$router->get('/{$resource}', [\App\Controllers\\{$controllerClass}::class, 'index']);
    \$router->get('/{$resource}/{id}', [\App\Controllers\\{$controllerClass}::class, 'show']);
    \$router->post('/{$resource}', [\App\Controllers\\{$controllerClass}::class, 'store'])
        ->middleware([JsonMiddleware::class]);
    \$router->put('/{$resource}/{id}', [\App\Controllers\\{$controllerClass}::class, 'update'])
        ->middleware([JsonMiddleware::class]);
    \$router->delete('/{$resource}/{id}', [\App\Controllers\\{$controllerClass}::class, 'delete']);

PHP;

        // Insert routes BEFORE the last "});" (closing of the /api group)
        $lastClose = strrpos($content, '});');
        if ($lastClose !== false) {
            $content = substr_replace($content, $routes, $lastClose, 0);
        } else {
            $content = rtrim($content) . "\n" . $routes;
        }

        file_put_contents($routeFile, $content);
        $this->write('Updated: routes/api.php');
        return true;
    }

    private function generateTest(string $resource, string $model): bool
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . $resource . '_test.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: tests/' . $resource . '_test.php');
            return false;
        }

        $sClass = $this->studly($resource);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

/**
 * Integration test for {$model} API.
 *
 * Run: php tests/{$resource}_test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Siro\Core\App;
use Siro\Core\Request;
use Siro\Core\Response;

\$basePath = dirname(__DIR__);

\$app = new App(\$basePath);
\$app->boot();
\$app->loadRoutes(\$basePath . '/routes/api.php');

echo "=== {$model} API Test ===\n\n";

\$passed = 0;
\$failed = 0;

function test(string \$name, callable \$fn): void
{
    global \$passed, \$failed;
    try {
        \$fn();
        echo "  \\033[32m✓\\033[0m {\$name}\n";
        \$passed++;
    } catch (\Throwable \$e) {
        echo "  \\033[31m✗ {\$name}: {\$e->getMessage()}\\033[0m\n";
        echo "    File: {\$e->getFile()}:{\$e->getLine()}\n";
        \$failed++;
    }
}

function dispatch(string \$method, string \$path, array \$body = [], array \$headers = []): array
{
    global \$app;
    ob_start();
    try {
        \$request = new Request(\$method, \$path, [], \$headers, \$body, '127.0.0.1');
        \$response = \$app->router->dispatch(\$request);
        ob_end_clean();
        return [
            'status' => \$response->statusCode(),
            'body' => json_decode(json_encode(\$response->payload()), true),
        ];
    } catch (\Siro\Core\ValidationException \$e) {
        ob_end_clean();
        \$response = \$e->toResponse();
        return [
            'status' => \$response->statusCode(),
            'body' => json_decode(json_encode(\$response->payload()), true),
        ];
    } catch (\Throwable \$e) {
        ob_end_clean();
        throw \$e;
    }
}

// ─── Tests ──────────────────────────────────────────

test('GET /api/{$resource} returns list', function () {
    \$res = dispatch('GET', '/api/{$resource}');
    assert(\$res['status'] === 200, 'Expected 200, got ' . \$res['status']);
});

test('GET /api/{$resource}/999 returns 404', function () {
    \$res = dispatch('GET', '/api/{$resource}/999');
    assert(\$res['status'] === 404, 'Expected 404, got ' . \$res['status']);
    assert(\$res['body']['success'] === false, 'Expected success=false');
});

test('POST /api/{$resource} with valid data', function () {
    \$res = dispatch('POST', '/api/{$resource}', ['name' => 'Test {$sClass}']);
    assert(\$res['status'] === 201, 'Expected 201, got ' . \$res['status']);
    assert(\$res['body']['success'] === true, 'Expected success=true');
});

test('POST /api/{$resource} without name returns 422', function () {
    \$res = dispatch('POST', '/api/{$resource}', []);
    assert(\$res['status'] === 422, 'Expected 422, got ' . \$res['status']);
});

echo "\n=== Results ===\n";
echo "Passed: {\$passed}\n";
echo "Failed: {\$failed}\n";
exit(\$failed > 0 ? 1 : 0);

PHP);
        $this->write('Generated: tests/' . $resource . '_test.php');
        $this->write('  Run: php tests/' . $resource . '_test.php');
        return true;
    }
}
