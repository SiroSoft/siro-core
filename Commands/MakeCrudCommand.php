<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeCrudCommand
{
    use CommandSupport {
        confirmOverwrite as traitConfirmOverwrite;
    }

    private bool $forceOverwrite = false;

    public function __construct(private readonly string $basePath)
    {
    }

    protected function confirmOverwrite(string $basePath, string $path): bool
    {
        return $this->forceOverwrite ? true : $this->traitConfirmOverwrite($basePath, $path);
    }

    public function run(array $args): int
    {
        $resource = trim((string) ($args[0] ?? ''));

        if ($resource === '') {
            $this->write('Usage: php siro make:crud <name> [--seed] [--simple]');
            return 1;
        }

        $this->forceOverwrite = in_array('--force', $args, true);
        $model = ucfirst($this->studly($this->singular($resource)));
        $classBase = $model;
        $controllerClass = $model . 'Controller';
        $resourceClass = $model . 'Resource';
        $table = $this->plural(strtolower($resource));

        $isSimple = in_array('--simple', $args, true);
        $withSeed = in_array('--seed', $args, true);

        $withoutService = $isSimple || in_array('--without-service', $args, true);
        $withoutRepository = $isSimple || in_array('--without-repository', $args, true);

        $serviceName = str_replace('Resource', 'Service', $resourceClass);
        $repoName = str_replace('Resource', 'Repository', $resourceClass);

        $mode = $isSimple ? 'Simple' : 'Full';
        $this->write("Generating {$mode} CRUD for: {$resource}");
        $this->write('');

        $ok = true;

        // 1. Model
        $ok = $this->generateModel($model, $table) && $ok;

        // 2. Migration (skip in simple mode)
        if (!$isSimple) {
            $ok = $this->generateMigration($table, $model) && $ok;
        }

        // 3. Repository (skip in simple mode)
        if (!$withoutRepository) {
            $ok = $this->generateRepository($repoName, $model, $table) && $ok;
        }

        // 4. Service (skip in simple mode)
        if (!$withoutService) {
            $ok = $this->generateService($serviceName, $model, $repoName, $withoutRepository) && $ok;
        }

        // 5. Controller
        $ok = $this->generateController($controllerClass, $model, $resourceClass, $serviceName, $withoutService) && $ok;

        // 6. Resource (skip in simple mode)
        if (!$isSimple) {
            $ok = $this->generateResource($resourceClass) && $ok;
        }

        // 7. Routes
        $ok = $this->generateRoutes($resource, $controllerClass) && $ok;

        // 8. Test (skip in simple mode)
        if (!$isSimple) {
            $ok = $this->generateTest($resource, $model) && $ok;
        }

        $this->write('');
        if ($ok) {
            $this->write('');
            $this->write('  ' . str_repeat('=', 54));
            $this->write('  ' . $mode . ' CRUD — ' . $classBase . ' created successfully!');
            $this->write('  ' . str_repeat('=', 54));
            $this->write('');
            $this->write('  Next steps:');
            $this->write('');
            $step = 1;
            if (!$isSimple) {
                $this->write('  ' . $step . '. Run migration:');
                $this->write('     php siro migrate');
                $this->write('');
                $step++;
                if ($withSeed) {
                    $this->write('     php siro db:seed');
                    $this->write('');
                }
            }
            $this->write('  ' . $step . '. Start dev server:');
            $this->write('     php siro serve');
            $this->write('');
            $step++;
            $this->write('  ' . $step . '. Test API:');
            $this->write('     php siro api:test GET /api/' . $resource);
            $this->write('');
            $step++;
            $this->write('  ' . $step . '. Debug request:');
            $this->write('     php siro log:trace');
            $this->write('     php siro log:replay <trace_id>');
            $this->write('');
            $this->write('  ' . str_repeat('-', 54));
            $this->write('  Need help? → php siro list');
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

use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

return new class {
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$t) {
            \$t->id();
            \$t->string('name');
            {$columns}
            \$t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('{$table}');
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

    private function generateController(string $class, string $model, string $resource, string $serviceName, bool $withoutService): bool
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Controllers/' . $class . '.php');
            return false;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $rules = $this->guessValidationRules($model);

        if ($withoutService) {
            // Controller -> Model directly
            file_put_contents($path, $this->controllerWithModel($class, $model, $resource, $rules));
        } else {
            // Controller -> Service -> Model
            file_put_contents($path, $this->controllerWithService($class, $model, $resource, $serviceName, $rules));
        }
        $this->write('Generated: app/Controllers/' . $class . '.php');
        return true;
    }

    private function controllerWithModel(string $class, string $model, string $resource, string $rules): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\\{$model};
use App\Resources\\{$resource};
use Siro\Core\Request;
use Siro\Core\Response;

final class {$class}
{
    public function index(Request \$request): Response
    {
        \$result = {$model}::query()->orderBy('id', 'DESC')->paginate(\$request->queryInt('per_page', 20), \$request->queryInt('page', 1));
        return Response::paginated({$resource}::collection(\$result['data']), \$result['meta'], '{$model} list');
    }

    public function show(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \$item = {$model}::find(\$id);
        if (\$item === null) return Response::error('{$model} not found', 404);
        return Response::success({$resource}::make(\$item), '{$model} detail');
    }

    public function store(Request \$request): Response
    {
        \$data = \$request->validate([{$rules}]);
        \$item = {$model}::create(\$data + ['created_at' => date('Y-m-d H:i:s')]);
        return Response::created({$resource}::make(\$item), '{$model} created');
    }

    public function update(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \$item = {$model}::find(\$id);
        if (\$item === null) return Response::error('{$model} not found', 404);
        \$item->update(\$request->validate([{$rules}]));
        return Response::success({$resource}::make(\$item), '{$model} updated');
    }

    public function delete(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \$item = {$model}::find(\$id);
        if (\$item === null) return Response::error('{$model} not found', 404);
        \$item->delete();
        return Response::success(null, '{$model} deleted');
    }
}
PHP;
    }

    private function controllerWithService(string $class, string $model, string $resource, string $serviceName, string $rules): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\\{$serviceName};
use App\Resources\\{$resource};
use Siro\Core\Request;
use Siro\Core\Response;

final class {$class}
{
    public function __construct(private readonly {$serviceName} \$service)
    {
    }

    public function index(Request \$request): Response
    {
        \$result = \$this->service->getAll(\$request->queryInt('page', 1), \$request->queryInt('per_page', 20));
        return Response::paginated({$resource}::collection(\$result['data']), \$result['meta'], '{$model} list');
    }

    public function show(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \$item = \$this->service->getById(\$id);
        if (\$item === null) return Response::error('{$model} not found', 404);
        return Response::success({$resource}::make(\$item), '{$model} detail');
    }

    public function store(Request \$request): Response
    {
        \$item = \$this->service->create(\$request->validate([{$rules}]));
        return Response::created({$resource}::make(\$item), '{$model} created');
    }

    public function update(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        \$item = \$this->service->update(\$id, \$request->validate([{$rules}]));
        if (\$item === null) return Response::error('{$model} not found', 404);
        return Response::success({$resource}::make(\$item), '{$model} updated');
    }

    public function delete(Request \$request): Response
    {
        \$id = (int) \$request->param('id');
        if (\$id <= 0) return Response::error('Invalid id', 422);
        return \$this->service->delete(\$id)
            ? Response::success(null, '{$model} deleted')
            : Response::error('{$model} not found', 404);
    }
}
PHP;
    }

    private function guessValidationRules(string $model): string
    {
        $name = strtolower($model);
        $rules = [];

        if (str_contains($name, 'user') || str_contains($name, 'customer') || str_contains($name, 'person')) {
            $rules[] = "'name' => 'required|min:2|max:100'";
            $rules[] = "'email' => 'required|email'";
        } elseif (str_contains($name, 'order') || str_contains($name, 'invoice')) {
            $rules[] = "'customer_id' => 'required|integer'";
            $rules[] = "'total' => 'required|numeric|min:0'";
        } elseif (str_contains($name, 'product') || str_contains($name, 'item')) {
            $rules[] = "'name' => 'required|min:2|max:200'";
            $rules[] = "'price' => 'required|numeric|min:0'";
            $rules[] = "'sku' => 'required|min:2|max:50'";
        } elseif (str_contains($name, 'category') || str_contains($name, 'tag')) {
            $rules[] = "'name' => 'required|min:2|max:100'";
            $rules[] = "'slug' => 'required|min:2|max:100'";
        } else {
            $rules[] = "'name' => 'required|min:2|max:255'";
        }

        return implode(",\n            ", $rules);
    }

    private function generateService(string $serviceName, string $model, string $repoName, bool $withoutRepository): bool
    {
        $path = $this->basePath . '/app/Services/' . $serviceName . '.php';
        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Services/' . $serviceName . '.php');
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        if ($withoutRepository) {
            // Service -> Model directly
            file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\\{$model};

final class {$serviceName}
{
    public function __construct(private readonly {$model} \$model)
    {
    }

    public function getAll(int \$page = 1, int \$perPage = 20): array
    {
        return {$model}::query()->orderBy('id', 'DESC')->paginate(\$perPage, \$page);
    }

    public function getById(int \$id): mixed
    {
        return {$model}::find(\$id);
    }

    public function create(array \$data): mixed
    {
        return {$model}::create(\$data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    public function update(int \$id, array \$data): mixed
    {
        \$item = {$model}::find(\$id);
        if (\$item === null) return null;
        \$item->update(\$data);
        return \$item;
    }

    public function delete(int \$id): bool
    {
        \$item = {$model}::find(\$id);
        return \$item !== null && (bool) \$item->delete();
    }
}

PHP);
        } else {
            // Service -> Repository -> Model
            file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\\{$repoName};
use App\Models\\{$model};

final class {$serviceName}
{
    public function __construct(private readonly {$repoName} \$repo)
    {
    }

    public function getAll(int \$page = 1, int \$perPage = 20): array
    {
        return \$this->repo->findAll(\$page, \$perPage);
    }

    public function getById(int \$id): mixed
    {
        return \$this->repo->findById(\$id);
    }

    public function create(array \$data): mixed
    {
        return \$this->repo->store(\$data);
    }

    public function update(int \$id, array \$data): mixed
    {
        return \$this->repo->update(\$id, \$data);
    }

    public function delete(int \$id): bool
    {
        return \$this->repo->destroy(\$id);
    }
}

PHP);
        }
        $this->write('Generated: app/Services/' . $serviceName . '.php');
        return true;
    }

    private function generateRepository(string $repoName, string $model, string $table): bool
    {
        $path = $this->basePath . '/app/Repositories/' . $repoName . '.php';
        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: app/Repositories/' . $repoName . '.php');
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\\{$model};

final class {$repoName}
{
    public function findAll(int \$page = 1, int \$perPage = 20): array
    {
        return {$model}::query()->orderBy('id', 'DESC')->paginate(\$perPage, \$page);
    }

    public function findById(int \$id): mixed
    {
        return {$model}::find(\$id);
    }

    public function store(array \$data): mixed
    {
        return {$model}::create(\$data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    public function update(int \$id, array \$data): mixed
    {
        \$item = {$model}::find(\$id);
        if (\$item === null) return null;
        \$item->update(\$data);
        return \$item;
    }

    public function destroy(int \$id): bool
    {
        \$item = {$model}::find(\$id);
        if (\$item === null) return false;
        return (bool) \$item->delete();
    }
}

PHP);
        $this->write('Generated: app/Repositories/' . $repoName . '.php');
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
            if (!$this->forceOverwrite) {
                $this->write('Routes already exist for ' . $resource . '. Skipped.');
                return false;
            }
            // Remove existing routes block for this resource (multi-line safe)
            $content = preg_replace('/\s*\/\/ Generated by: php siro make:crud ' . preg_quote($resource, '/') . '.*?(?=\n\s*\/\/ Generated|$)/s', "\n", $content);
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
        $className = $this->studly($resource) . 'Test';
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . $className . '.php';

        if (is_file($path) && !$this->confirmOverwrite($this->basePath, $path)) {
            $this->write('Skipped: tests/Feature/' . $className . '.php');
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $endpoint = '/api/' . $resource;

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Tests\TestCase;

final class {$className} extends TestCase
{
    public function testIndexReturns200(): void
    {
        \$app = \$this->createApp();
        \$response = \$this->dispatch(\$app, 'GET', '{$endpoint}');
        \$this->assertEquals(200, \$response->statusCode());
    }

    public function testShowReturns404ForInvalidId(): void
    {
        \$app = \$this->createApp();
        \$response = \$this->dispatch(\$app, 'GET', '{$endpoint}/999');
        \$this->assertEquals(404, \$response->statusCode());
    }

    public function testStoreReturns201WithValidData(): void
    {
        \$app = \$this->createApp();
        \$response = \$this->dispatch(\$app, 'POST', '{$endpoint}', ['name' => 'Test {$model}']);
        \$this->assertEquals(201, \$response->statusCode());
    }

    public function testStoreReturns422WithoutRequiredFields(): void
    {
        \$app = \$this->createApp();
        \$response = \$this->dispatch(\$app, 'POST', '{$endpoint}', []);
        \$this->assertEquals(422, \$response->statusCode());
    }
}

PHP);
        $this->write('Generated: tests/Feature/' . $className . '.php');
        $this->write('  Run: vendor/bin/phpunit --testsuite=Feature --filter=' . $className);
        return true;
    }
}
