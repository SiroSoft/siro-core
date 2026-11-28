<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Config;
use Siro\Core\Console;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Logger;
use Siro\Core\ScheduleTask;

/**
 * Regression tests for the CLI smoke-fix gate lane (27/11/2026):
 * PHPStan gate (ScheduleTask), S-01 substr off-by-one family,
 * S-08 --host= scheme doubling, MAKE-M4 --flow=auth, MAKE-M5
 * --flow=bogus, MAKE-M6 relative --output, MAKE-M8
 * make:test --from-trace --ignore=.
 */
final class SmokeFixGateTest extends TestCase
{
    private Console $console;
    private string $tempDir;

    protected function setUp(): void
    {
        Env::reset();
        Config::reset();
        Database::purgeAll();
        Cache::reset();
        Logger::reset();

        $this->tempDir = sys_get_temp_dir() . '/siro_smoke_fix_gate_' . bin2hex(random_bytes(4));
        foreach (
            [
            'app/Controllers', 'app/Resources', 'app/Models', 'routes', 'config',
            'storage/logs/traces', 'storage/framework', 'docs', 'tests/Feature',
            ] as $dir
        ) {
            mkdir($this->tempDir . '/' . $dir, 0777, true);
        }

        file_put_contents(
            $this->tempDir . '/.env',
            "APP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=testing_app_key_for_hmac_32chars!!\n"
            . "DB_CONNECTION=sqlite\nDB_DATABASE=" . $this->tempDir . "/storage/test.db\n"
            . "SIRO_OPENAPI_ENABLED=true\n"
        );
        file_put_contents(
            $this->tempDir . '/config/database.php',
            '<?php return ["driver" => "sqlite", "database" => "' . $this
                ->tempDir . '/storage/test.db", "charset" => "utf8mb4"];'
        );
        file_put_contents(
            $this->tempDir . '/config/app.php',
            '<?php return ["name" => "SmokeFixGate", "env" => "testing"];'
        );

        // Controllers exist so make:openapi fallback mode can derive
        // routes from them (no routes/*.php => fallback).
        file_put_contents($this->tempDir . '/app/Controllers/AuthController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

class AuthController
{
    public function index(): array { return []; }
    public function show(int $id): array { return []; }
    public function store(): array { return []; }
    public function update(int $id): array { return []; }
    public function delete(int $id): array { return []; }
}
PHP);
        file_put_contents($this->tempDir . '/app/Controllers/UserController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Controllers;

class UserController
{
    public function index(): array { return []; }
    public function show(int $id): array { return []; }
    public function store(): array { return []; }
    public function update(int $id): array { return []; }
    public function delete(int $id): array { return []; }
}
PHP);

        $this->console = new Console($this->tempDir);
    }

    protected function tearDown(): void
    {
        Env::reset();
        Config::reset();
        Database::purgeAll();
        Cache::reset();
        Logger::reset();
        $this->rrmdir($this->tempDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param array<int, string> $argv @return array{0: int, 1: string} */
    private function runCmd(array $argv): array
    {
        ob_start();
        $exit = $this->console->run($argv);
        $output = (string) ob_get_clean();
        return [$exit, $output];
    }

    private function writeTrace(string $id, string $path, string $host = '127.0.0.1:18101'): void
    {
        $data = [
            'trace_id' => $id,
            'method' => 'GET',
            'path' => $path,
            'status' => 200,
            'time_ms' => 5,
            'host' => $host,
            'ip' => '127.0.0.1',
            'request_body' => '{}',
            'response_body' => '{}',
            'timestamp' => date('c'),
        ];
        file_put_contents(
            $this->tempDir . '/storage/logs/traces/' . $id . '.json',
            json_encode($data)
        );
    }

    /** @return array<string, mixed> */
    private function runOpenApi(array $extraArgs): array
    {
        [$exit, $output] = $this->runCmd(array_merge(['siro', 'make:openapi'], $extraArgs));
        $this->assertSame(0, $exit, 'make:openapi should exit 0: ' . $output);
        $specFile = $this->tempDir . '/docs/openapi/openapi.json';
        $this->assertFileExists($specFile, 'openapi.json should be generated');
        $spec = json_decode((string) file_get_contents($specFile), true);
        $this->assertIsArray($spec, 'spec must be valid JSON');
        return $spec;
    }

    // ---------------- Gate: ScheduleTask::describe() ----------------

    public function testDescribeArrayCallable(): void
    {
        $task = new ScheduleTask('call', ['App\\Foo', 'bar']);
        $this->assertSame('App\\Foo::bar', $task->describe());

        $task = new ScheduleTask('call', [$this, 'testDescribeArrayCallable']);
        $this->assertSame(self::class . '::testDescribeArrayCallable', $task->describe());

        // Scalars keep their old (string)-cast rendering.
        $task = new ScheduleTask('call', [42, 7]);
        $this->assertSame('42::7', $task->describe());

        $task = new ScheduleTask('command', 'queue:work');
        $this->assertSame('queue:work', $task->describe());

        $task = new ScheduleTask('call', function () {
        });
        $this->assertSame('Closure', $task->describe());
    }

    // ---------------- S-01: db:show --limit=N ----------------

    public function testDbShowLimitRespectsFlag(): void
    {
        $pdo = new \PDO('sqlite:' . $this->tempDir . '/storage/test.db');
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
        for ($i = 1; $i <= 7; $i++) {
            $pdo->exec("INSERT INTO widgets (name) VALUES ('Widget {$i}')");
        }
        $pdo = null;

        [$exit, $output] = $this->runCmd(['siro', 'db:show', 'widgets', '--limit=2']);
        $this->assertSame(0, $exit, 'db:show should exit 0: ' . $output);
        $this->assertStringContainsString('Showing 2 of 7 rows', $output);

        [$exit, $output] = $this->runCmd(['siro', 'db:show', 'widgets', '--limit=5']);
        $this->assertSame(0, $exit, 'db:show should exit 0: ' . $output);
        $this->assertStringContainsString('Showing 5 of 7 rows', $output);
    }

    // ---------------- S-01: log:trace --limit=N ----------------

    public function testLogTraceLimitRespectsFlag(): void
    {
        $this->writeTrace('tr-a', '/api/widgets');
        $this->writeTrace('tr-b', '/api/widgets');
        $this->writeTrace('tr-c', '/api/orders');

        [$exit, $output] = $this->runCmd(['siro', 'log:trace', '--limit=2']);
        $this->assertSame(0, $exit, 'log:trace should exit 0: ' . $output);
        $this->assertStringContainsString('Total: 2 traces', $output);

        // Guards: the neighbouring --days=/--path= parses (substr 7, correct)
        // must keep working after the --limit= fix.
        [$exit, $output] = $this->runCmd(['siro', 'log:trace', '--path=/api/orders', '--limit=10']);
        $this->assertSame(0, $exit, 'log:trace --path should exit 0: ' . $output);
        $this->assertStringContainsString('Total: 1 traces', $output);

        [$exit, $output] = $this->runCmd(['siro', 'log:trace', '--days=30', '--limit=10']);
        $this->assertSame(0, $exit, 'log:trace --days should exit 0: ' . $output);
        $this->assertStringContainsString('Total: 3 traces', $output);
    }

    // ---------------- S-01: test:regression --limit=N ----------------

    public function testRegressionLimitRespectsFlag(): void
    {
        $this->writeTrace('rg-a', '/api/widgets');
        $this->writeTrace('rg-b', '/api/widgets');
        $this->writeTrace('rg-c', '/api/orders');

        // No server listens on 127.0.0.1:18101, so every replay fails
        // fast with connection_error; only the "Replaying N" count matters.
        [$exit, $output] = $this->runCmd(['siro', 'test:regression', '--limit=2']);
        $this->assertStringContainsString('Replaying 2 traces', $output);
        $this->assertStringNotContainsString('Replaying 1 traces', $output);
        $this->assertSame(1, $exit, 'all replays fail (no server), exit must be 1');
    }

    // ---------------- S-01: make:openapi --method= ----------------

    public function testOpenApiMethodFilter(): void
    {
        $spec = $this->runOpenApi(['--method=GET']);
        $this->assertCount(4, $spec['paths'], 'GET filter must keep 4 fallback paths');
        foreach ($spec['paths'] as $path => $ops) {
            $this->assertArrayHasKey('get', $ops, "GET {$path} must exist");
            $this->assertArrayNotHasKey('post', $ops, "POST {$path} must be filtered out");
        }

        $spec = $this->runOpenApi(['--method=get']);
        $this->assertCount(4, $spec['paths'], 'lowercase --method=get must behave like GET');

        $spec = $this->runOpenApi(['--method=POST']);
        $this->assertCount(2, $spec['paths'], 'POST filter must keep 2 fallback paths');
        foreach ($spec['paths'] as $path => $ops) {
            $this->assertArrayHasKey('post', $ops, "POST {$path} must exist");
            $this->assertArrayNotHasKey('get', $ops, "GET {$path} must be filtered out");
        }
    }

    // ---------------- S-08: --host= with scheme ----------------

    public function testOpenApiHostWithScheme(): void
    {
        $spec = $this->runOpenApi(['--host=https://api.example.test']);
        $this->assertSame('https://api.example.test', $spec['servers'][0]['url']);

        $spec = $this->runOpenApi(['--host=http://api.example.test']);
        $this->assertSame('http://api.example.test', $spec['servers'][0]['url']);

        $spec = $this->runOpenApi(['--host=api.example.test']);
        $this->assertSame('http://api.example.test', $spec['servers'][0]['url']);
    }

    public function testPostmanHostWithScheme(): void
    {
        $this->writePostmanRoutes();
        $collection = $this->runPostman(['--host=https://api.example.test']);
        $this->assertSame('https://api.example.test', $this->postmanBaseUrl($collection));

        $collection = $this->runPostman(['--host=api.example.test']);
        $this->assertSame('http://api.example.test', $this->postmanBaseUrl($collection));
    }

    // ---------------- MAKE-M4: --flow=auth ----------------

    public function testOpenApiFlowAuthKeepsBareAuthPath(): void
    {
        $spec = $this->runOpenApi(['--flow=auth']);
        $paths = $spec['paths'] ?? [];
        $this->assertArrayHasKey('/api/auth', $paths, 'bare /api/auth must survive --flow=auth');
        $this->assertArrayHasKey('get', $paths['/api/auth'], 'GET /api/auth must survive');
        $this->assertArrayHasKey('post', $paths['/api/auth'], 'POST /api/auth must survive');
        $this->assertArrayHasKey('/api/auth/{id}', $paths, '/api/auth/{id} must survive');
        $this->assertArrayNotHasKey('/api/user', $paths, 'non-auth paths must be filtered out');
        $this->assertArrayNotHasKey('/api/user/{id}', $paths, 'non-auth paths must be filtered out');
        $this->assertSame(
            'Auth',
            $paths['/api/auth']['get']['tags'][0],
            'bare /api/auth must be tagged Auth, not Auths'
        );
    }

    public function testPostmanFlowAuthKeepsBareAuthPath(): void
    {
        $this->writePostmanRoutes();
        $collection = $this->runPostman(['--flow=auth']);
        $names = $this->postmanRequestNames($collection);
        $this->assertContains('POST /api/auth', $names, 'POST /api/auth (login) must survive --flow=auth');
        $this->assertContains('GET /api/auth', $names, 'GET /api/auth must survive --flow=auth');
        $this->assertContains('GET /api/auth/{id}', $names);
        $this->assertNotContains('GET /api/users', $names, 'non-auth requests must be filtered out');

        $collection = $this->runPostman(['--flow=crud']);
        $names = $this->postmanRequestNames($collection);
        $this->assertNotContains('POST /api/auth', $names, 'bare /api/auth must not leak into --flow=crud');
        $this->assertContains('GET /api/users', $names);
    }

    // ---------------- MAKE-M5: --flow=bogus ----------------

    public function testOpenApiFlowBogusFails(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'make:openapi', '--flow=bogus']);
        $this->assertSame(1, $exit, 'bogus --flow must fail');
        $this->assertStringContainsString("Invalid --flow value 'bogus'", $output);
        $this->assertFileDoesNotExist(
            $this->tempDir . '/docs/openapi/openapi.json',
            'no spec must be written for an invalid --flow'
        );
    }

    public function testPostmanFlowBogusFails(): void
    {
        $this->writePostmanRoutes();
        [$exit, $output] = $this->runCmd(['siro', 'make:postman', '--flow=bogus']);
        $this->assertSame(1, $exit, 'bogus --flow must fail');
        $this->assertStringContainsString("Invalid --flow value 'bogus'", $output);
    }

    public function testFlowCrudStillValid(): void
    {
        $spec = $this->runOpenApi(['--flow=crud']);
        $this->assertNotEmpty($spec['paths']);
    }

    // ---------------- MAKE-M6: relative --output ----------------

    public function testOpenApiRelativeOutputGoesToProject(): void
    {
        $relName = 'rel-spec-' . bin2hex(random_bytes(4)) . '.json';
        [$exit, $output] = $this->runCmd(['siro', 'make:openapi', '--output=' . $relName]);
        $this->assertSame(0, $exit, 'make:openapi should exit 0: ' . $output);
        $this->assertFileExists($this->tempDir . '/' . $relName, 'relative --output must land in the project root');
        $this->assertFileDoesNotExist(getcwd() . '/' . $relName, 'relative --output must NOT escape to the CWD');

        $absPath = $this->tempDir . '/abs-spec.json';
        [$exit] = $this->runCmd(['siro', 'make:openapi', '--output=' . $absPath]);
        $this->assertSame(0, $exit);
        $this->assertFileExists($absPath, 'absolute --output must keep working');
    }

    public function testPostmanRelativeOutputGoesToProject(): void
    {
        $this->writePostmanRoutes();
        $relName = 'rel-col-' . bin2hex(random_bytes(4)) . '.json';
        [$exit, $output] = $this->runCmd(['siro', 'make:postman', '--output=' . $relName]);
        $this->assertSame(0, $exit, 'make:postman should exit 0: ' . $output);
        $this->assertFileExists($this->tempDir . '/' . $relName, 'relative --output must land in the project root');
        $this->assertFileDoesNotExist(getcwd() . '/' . $relName, 'relative --output must NOT escape to the CWD');
    }

    // ---------------- MAKE-M8: make:test --from-trace --ignore= ----------------

    public function testMakeTestFromTraceIgnore(): void
    {
        $trace = [
            'method' => 'POST',
            'path' => '/api/orders',
            'status' => 201,
            'request_body' => '{"name":"Widget","price":9.99}',
            'response_body' => '{"success":true,"id":5,"name":"Widget"}',
            'auth_header' => 'Bearer x',
            'queries' => [['sql' => "INSERT INTO orders (name) VALUES ('Widget')"]],
        ];
        file_put_contents(
            $this->tempDir . '/storage/logs/traces/tr1.json',
            json_encode($trace)
        );
        $generated = $this->tempDir . '/tests/Feature/FromTracetr1Test.php';

        [$exit, $output] = $this->runCmd(['siro', 'make:test', '--from-trace=tr1']);
        $this->assertSame(0, $exit, 'make:test --from-trace should exit 0: ' . $output);
        $this->assertFileExists($generated);
        $plain = (string) file_get_contents($generated);
        $this->assertStringContainsString("assertArrayHasKey('id', \$body)", $plain);
        $this->assertStringContainsString("assertArrayHasKey('name', \$body)", $plain);

        [$exit, $output] = $this->runCmd(['siro', 'make:test', '--from-trace=tr1', '--ignore=id,created_at']);
        $this->assertSame(0, $exit, 'make:test --from-trace --ignore should exit 0: ' . $output);
        $ignored = (string) file_get_contents($generated);
        $this->assertStringNotContainsString(
            "assertArrayHasKey('id', \$body)",
            $ignored,
            '--ignore=id must drop the id assertion'
        );
        $this->assertStringContainsString("assertArrayHasKey('name', \$body)", $ignored, 'non-ignored keys must stay');
        $this->assertNotSame($plain, $ignored, '--ignore must change the generated file');
    }

    // ---------------- postman helpers ----------------

    private function writePostmanRoutes(): void
    {
        file_put_contents($this->tempDir . '/routes/api.php', <<<'PHP'
<?php

declare(strict_types=1);

$router->post('/api/auth', [\App\Controllers\AuthController::class, 'store']);
$router->get('/api/auth', [\App\Controllers\AuthController::class, 'index']);
$router->get('/api/auth/{id}', [\App\Controllers\AuthController::class, 'show']);
$router->get('/api/users', [\App\Controllers\UserController::class, 'index']);
$router->post('/api/users', [\App\Controllers\UserController::class, 'store']);
PHP);
    }

    /** @return array<string, mixed> */
    private function runPostman(array $extraArgs): array
    {
        [$exit, $output] = $this->runCmd(array_merge(['siro', 'make:postman'], $extraArgs));
        $this->assertSame(0, $exit, 'make:postman should exit 0: ' . $output);
        $collectionFile = $this->tempDir . '/docs/postman/collection.json';
        $this->assertFileExists($collectionFile, 'collection.json should be generated');
        $collection = json_decode((string) file_get_contents($collectionFile), true);
        $this->assertIsArray($collection, 'collection must be valid JSON');
        return $collection;
    }

    /** @param array<string, mixed> $collection */
    private function postmanBaseUrl(array $collection): string
    {
        foreach ($collection['variable'] ?? [] as $var) {
            if (($var['key'] ?? '') === 'base_url') {
                return (string) $var['value'];
            }
        }
        $this->fail('base_url variable not found in collection');
    }

    /** @param array<string, mixed> $collection @return array<int, string> */
    private function postmanRequestNames(array $collection): array
    {
        $names = [];
        foreach ($collection['item'] ?? [] as $folder) {
            foreach ($folder['item'] ?? [] as $item) {
                $names[] = (string) ($item['name'] ?? '');
            }
        }
        return $names;
    }
}
