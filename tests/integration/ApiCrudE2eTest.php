<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use Siro\Core\ApiCrudController;
use Siro\Core\App;
use Siro\Core\Commands\ApiContractCommand;
use Siro\Core\Database;
use Siro\Core\Gate;
use Siro\Core\Model;
use Siro\Core\ModelNotFoundException;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\Tests\TestCase;

/**
 * End-to-end proof for G3 auto-CRUD:
 *  - full CRUD lifecycle through the Router on sqlite :memory:;
 *  - `api:contract --strict` sees the 5 auto-generated routes (same source
 *    of routes G7 will consume — no special-casing in G3);
 *  - the same routes work through a booted App (routes file + config).
 *
 * The trial model mirrors a NEW SiroERP module (there is no Feedback model
 * in the app today): `App\Models\CustomerFeedback` shape — final class,
 * `$table` + `$fillable`, no hand-written controller, one `apiResource()`
 * line. Running the real SiroERP suite against core 1.5.0 stays an open
 * item for QC1/assembly (the app pins core ^1.3.1; see REPORT.md §5).
 *
 * @covers \Siro\Core\Router::apiResource
 * @covers \Siro\Core\ApiCrudController
 */
final class ApiCrudE2eTest extends TestCase
{
    private Router $router;
    private string $basePath = '';

    /** @var array<string, mixed> */
    private array $options = [
        'filterable' => ['status', 'rating', 'customer_id'],
        'sortable' => ['rating', 'customer_id'],
        'fields' => ['id', 'customer_id', 'rating', 'message', 'status'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::reset();
        ApiCrudController::reset();
        Model::clearIdentityMap();
        Database::purgeAll();

        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE customer_feedback '
            . '(id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, rating INTEGER NOT NULL, '
            . 'message TEXT, status TEXT NOT NULL DEFAULT \'new\')');

        $this->router = new Router();
    }

    protected function tearDown(): void
    {
        if ($this->basePath !== '') {
            $this->removeDir($this->basePath);
            $this->basePath = '';
        }
        Gate::reset();
        ApiCrudController::reset();
        Model::clearIdentityMap();
        Database::purgeAll();
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function allowAll(): void
    {
        Gate::define('customer_feedback.view', static fn (): bool => true);
        Gate::define('customer_feedback.create', static fn (): bool => true);
        Gate::define('customer_feedback.update', static fn (): bool => true);
        Gate::define('customer_feedback.delete', static fn (): bool => true);
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $body */
    private function dispatch(string $method, string $path, array $query = [], array $body = []): mixed
    {
        return $this->router->dispatch(new Request($method, $path, $query, [], $body));
    }

    public function testFullCrudLifecycle(): void
    {
        $this->allowAll();
        $this->router->apiResource(E2eFeedback::class, $this->options);

        // CREATE (201)
        $created = $this->dispatch('POST', '/customer_feedback', [], [
            'customer_id' => 7, 'rating' => 5, 'message' => 'Great service', 'status' => 'new',
        ]);
        $this->assertSame(201, $created->statusCode());
        /** @var array<string, mixed> $createdPayload */
        $createdPayload = $created->payload();
        $id = (int) $createdPayload['data']['id'];
        $this->assertGreaterThan(0, $id);

        // LIST (200, 1 row)
        $listed = $this->dispatch('GET', '/customer_feedback');
        /** @var array<string, mixed> $listPayload */
        $listPayload = $listed->payload();
        $this->assertSame(1, $listPayload['meta']['total']);

        // FILTER (only matching rows)
        $filtered = $this->dispatch('GET', '/customer_feedback', ['filter' => ['rating' => ['gte' => '4']]]);
        /** @var array<string, mixed> $filteredPayload */
        $filteredPayload = $filtered->payload();
        $this->assertSame(1, $filteredPayload['meta']['total']);
        $none = $this->dispatch('GET', '/customer_feedback', ['filter' => ['status' => 'resolved']]);
        /** @var array<string, mixed> $nonePayload */
        $nonePayload = $none->payload();
        $this->assertSame(0, $nonePayload['meta']['total']);

        // SHOW (200)
        $shown = $this->dispatch('GET', '/customer_feedback/' . $id);
        /** @var array<string, mixed> $shownPayload */
        $shownPayload = $shown->payload();
        $this->assertSame('Great service', $shownPayload['data']['message']);

        // UPDATE (200)
        $updated = $this->dispatch('PUT', '/customer_feedback/' . $id, [], ['status' => 'resolved']);
        /** @var array<string, mixed> $updatedPayload */
        $updatedPayload = $updated->payload();
        $this->assertSame('resolved', $updatedPayload['data']['status']);

        // DELETE (204) then SHOW → 404 (ModelNotFoundException at Router level)
        $deleted = $this->dispatch('DELETE', '/customer_feedback/' . $id);
        $this->assertSame(204, $deleted->statusCode());
        $this->assertNull(E2eFeedback::find($id));

        $this->expectException(ModelNotFoundException::class);
        $this->dispatch('GET', '/customer_feedback/' . $id);
    }

    public function testContractStrictSeesAutoGeneratedRoutes(): void
    {
        $this->buildFixtureApp();

        file_put_contents($this->basePath . '/docs/openapi/openapi.json', (string) json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Feedback API', 'version' => '1.0.0'],
            'paths' => [
                '/customer_feedback' => [
                    'get' => ['responses' => ['200' => ['description' => 'ok']]],
                    'post' => ['responses' => ['201' => ['description' => 'created']]],
                ],
                '/customer_feedback/{id}' => [
                    'get' => ['responses' => ['200' => ['description' => 'ok']]],
                    'put' => ['responses' => ['200' => ['description' => 'ok']]],
                    'delete' => ['responses' => ['204' => ['description' => 'deleted']]],
                ],
            ],
        ]));

        ob_start();
        $code = (new ApiContractCommand($this->basePath))->run(['--strict']);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('5 passed, 0 warnings, 0 failed', $output);
    }

    public function testContractStrictFailsWhenSpecLacksAutoRoute(): void
    {
        $this->buildFixtureApp();

        // Spec declares only the collection GET: the other 4 auto routes are
        // missing from the spec, but contract checks spec→routes, so a spec
        // that omits operations still passes; what must FAIL is a spec
        // operation with no matching route. Declare a phantom route instead.
        file_put_contents($this->basePath . '/docs/openapi/openapi.json', (string) json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Feedback API', 'version' => '1.0.0'],
            'paths' => [
                '/customer_feedback' => [
                    'get' => ['responses' => ['200' => ['description' => 'ok']]],
                ],
                '/customer_feedback/{id}/publish' => [
                    'post' => ['responses' => ['200' => ['description' => 'ok']]],
                ],
            ],
        ]));

        ob_start();
        $code = (new ApiContractCommand($this->basePath))->run(['--strict']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('1 failed', $output);
    }

    public function testAutoCrudWorksThroughBootedApp(): void
    {
        $this->buildFixtureApp();
        $this->allowAll();

        $app = new App($this->basePath);
        $app->boot();
        foreach (glob($this->basePath . '/routes/*.php') ?: [] as $file) {
            $app->loadRoutes($file);
        }

        $routes = $app->router()->getRoutes();
        $this->assertCount(5, $routes);

        Database::connection()->exec(
            "INSERT INTO customer_feedback (customer_id, rating, message, status) VALUES (3, 4, 'Good', 'new')"
        );

        $response = $app->router()->dispatch(new Request('GET', '/customer_feedback'));
        $this->assertSame(200, $response->statusCode());
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertSame(1, $payload['meta']['total']);
        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];
        $this->assertSame('Good', $data[0]['message']);
    }

    private function buildFixtureApp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_apicrud_' . uniqid('', true);
        mkdir($this->basePath . '/routes', 0777, true);
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/docs/openapi', 0777, true);
        file_put_contents($this->basePath . '/.env', "APP_ENV=testing\nAPP_DEBUG=false\n");
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php return ['driver' => 'sqlite', 'database' => ':memory:'];"
        );
        file_put_contents(
            $this->basePath . '/routes/api.php',
            "<?php \$router->apiResource(\\Siro\\Core\\Tests\\Integration\\E2eFeedback::class, "
            . "['filterable' => ['status', 'rating', 'customer_id'], 'sortable' => ['rating', 'customer_id'], "
            . "'fields' => ['id', 'customer_id', 'rating', 'message', 'status']]);"
        );
    }
}
