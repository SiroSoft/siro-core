<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use InvalidArgumentException;
use Siro\Core\ApiCrudController;
use Siro\Core\AuthorizationException;
use Siro\Core\Database;
use Siro\Core\DB\Relations\BelongsTo;
use Siro\Core\FormRequest;
use Siro\Core\Gate;
use Siro\Core\Model;
use Siro\Core\ModelNotFoundException;
use Siro\Core\Request;
use Siro\Core\Resource;
use Siro\Core\Route;
use Siro\Core\Router;
use Siro\Core\Tests\TestCase;
use Siro\Core\ValidationException;

/** @covers \Siro\Core\Router::apiResource @covers \Siro\Core\ApiCrudController */
final class ApiCrudTest extends TestCase
{
    private Router $router;

    /** @var array<string, mixed> */
    private array $options = [
        'filterable' => ['status', 'category_id', 'price'],
        'sortable' => ['created_at', 'price', 'name'],
        'includable' => ['category'],
        'fields' => ['id', 'name', 'price', 'status', 'created_at'],
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
        $pdo->exec('CREATE TABLE api_crud_categories '
            . '(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE api_crud_products '
            . '(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, price REAL NOT NULL DEFAULT 0, '
            . 'status TEXT NOT NULL DEFAULT \'active\', category_id INTEGER, created_at TEXT, updated_at TEXT)');

        $this->router = new Router();
        Route::setRouter($this->router);
        Route::clearRoutes();
    }

    protected function tearDown(): void
    {
        Route::clearRoutes();
        Route::resetNamedRoutes();
        Router::resetStatic();
        Gate::reset();
        ApiCrudController::reset();
        Model::clearIdentityMap();
        Database::purgeAll();
        parent::tearDown();
    }

    private function allowAll(): void
    {
        Gate::define('api_crud_products.view', static fn (): bool => true);
        Gate::define('api_crud_products.create', static fn (): bool => true);
        Gate::define('api_crud_products.update', static fn (): bool => true);
        Gate::define('api_crud_products.delete', static fn (): bool => true);
    }

    private function seed(): void
    {
        ApiCrudCategory::create(['name' => 'Gadgets']);
        ApiCrudCategory::create(['name' => 'Tools']);
        ApiCrudProduct::create(['name' => 'Hammer', 'price' => 25, 'status' => 'active', 'category_id' => 2]);
        ApiCrudProduct::create(['name' => 'Drill', 'price' => 120, 'status' => 'active', 'category_id' => 2]);
        ApiCrudProduct::create(['name' => 'Lamp', 'price' => 40, 'status' => 'draft', 'category_id' => 1]);
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $body */
    private function dispatch(string $method, string $path, array $query = [], array $body = []): mixed
    {
        return $this->router->dispatch(new Request($method, $path, $query, [], $body));
    }

    // =========================================================================
    // Registration
    // =========================================================================

    public function testApiResourceRegistersFiveRoutes(): void
    {
        $routes = $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $this->assertSame(['index', 'show', 'store', 'update', 'delete'], array_keys($routes));
        $this->assertCount(5, $this->router->getRoutes());

        $listed = $this->router->getRoutes();
        $handlers = array_column($listed, 'handler');
        $this->assertContains('Siro\Core\ApiCrudController@index', $handlers);
        $this->assertContains('Siro\Core\ApiCrudController@show', $handlers);
        $this->assertContains('Siro\Core\ApiCrudController@store', $handlers);
        $this->assertContains('Siro\Core\ApiCrudController@update', $handlers);
        $this->assertContains('Siro\Core\ApiCrudController@destroy', $handlers);
        $paths = array_column($listed, 'path');
        $this->assertContains('/api_crud_products', $paths);
        $this->assertContains('/api_crud_products/{id}', $paths);
    }

    public function testApiResourceOnlyAndExcept(): void
    {
        $routes = $this->router->apiResource(ApiCrudProduct::class, $this->options, [], ['index', 'show']);
        $this->assertSame(['index', 'show'], array_keys($routes));

        $this->router->clearRoutes();
        ApiCrudController::reset();
        $routes = $this->router->apiResource(ApiCrudProduct::class, $this->options, [], [], ['delete']);
        $this->assertArrayNotHasKey('delete', $routes);
        $this->assertCount(4, $this->router->getRoutes());
    }

    public function testApiResourceOnlyAndExceptViaOptions(): void
    {
        // The spec (REPORT §1.1) puts 'only'/'except' inside the options array.
        $options = $this->options;
        $options['only'] = ['index'];
        $routes = $this->router->apiResource(ApiCrudProduct::class, $options);
        $this->assertSame(['index'], array_keys($routes));
        $this->assertCount(1, $this->router->getRoutes());

        $this->router->clearRoutes();
        ApiCrudController::reset();
        $options = $this->options;
        $options['except'] = ['delete'];
        $routes = $this->router->apiResource(ApiCrudProduct::class, $options);
        $this->assertArrayNotHasKey('delete', $routes);
        $this->assertCount(4, $this->router->getRoutes());
    }

    public function testApiResourceOptionsOnlyWinsOverParam(): void
    {
        // When both are given, the options key wins over the separate param.
        $options = $this->options;
        $options['only'] = ['index'];
        $routes = $this->router->apiResource(ApiCrudProduct::class, $options, [], ['show']);
        $this->assertSame(['index'], array_keys($routes));
        $this->assertCount(1, $this->router->getRoutes());
    }

    public function testApiResourceOptionsOnlyMustBeArrayOfActions(): void
    {
        $options = $this->options;
        $options['only'] = 'index';
        $this->expectException(InvalidArgumentException::class);
        $this->router->apiResource(ApiCrudProduct::class, $options);
    }

    public function testApiResourceOptionsOnlyRejectsUnknownAction(): void
    {
        $options = $this->options;
        $options['only'] = ['bogus'];
        $this->expectException(InvalidArgumentException::class);
        $this->router->apiResource(ApiCrudProduct::class, $options);
    }

    public function testApiResourceCustomUri(): void
    {
        $options = $this->options;
        $options['uri'] = 'shop-items';
        $this->router->apiResource(ApiCrudProduct::class, $options);

        $paths = array_column($this->router->getRoutes(), 'path');
        $this->assertContains('/shop-items', $paths);
        $this->assertContains('/shop-items/{id}', $paths);
    }

    public function testApiResourceInsideGroupPrefix(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->group('/api/v1', function (Router $router): void {
            $router->apiResource(ApiCrudProduct::class, $this->options);
        });

        $response = $this->dispatch('GET', '/api/v1/api_crud_products');
        $this->assertSame(200, $response->statusCode());
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertSame(3, $payload['meta']['total']);
    }

    public function testApiResourceRejectsNonModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->router->apiResource(\stdClass::class, $this->options);
    }

    public function testApiResourceRejectsBadUri(): void
    {
        $options = $this->options;
        $options['uri'] = 'bad uri!';

        $this->expectException(InvalidArgumentException::class);
        $this->router->apiResource(ApiCrudProduct::class, $options);
    }

    public function testApiResourceRejectsBadResourceClass(): void
    {
        $options = $this->options;
        $options['resource'] = \stdClass::class;

        $this->expectException(InvalidArgumentException::class);
        $this->router->apiResource(ApiCrudProduct::class, $options);
    }

    public function testApiResourceRejectsUnknownAction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->router->apiResource(ApiCrudProduct::class, $this->options, [], ['index', 'publish']);
    }

    // =========================================================================
    // Index: filter / sort / include / fields / pagination
    // =========================================================================

    public function testIndexReturnsPaginatedList(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products');
        $this->assertSame(200, $response->statusCode());
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertTrue($payload['success']);
        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];
        $this->assertCount(3, $data);
        $this->assertSame(3, $payload['meta']['total']);
        $this->assertSame(1, $payload['meta']['page']);
        $this->assertSame(15, $payload['meta']['per_page']);
    }

    public function testIndexFilterEq(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products', ['filter' => ['status' => 'draft']]);
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];
        $this->assertCount(1, $data);
        $this->assertSame('Lamp', $data[0]['name']);
        $this->assertSame(['status:eq:draft'], $payload['meta']['filters']);
    }

    public function testIndexFilterRangeAndSort(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products', [
            'filter' => ['price' => ['gte' => '30']],
            'sort' => '-price',
        ]);
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];
        $this->assertCount(2, $data);
        $this->assertSame('Drill', $data[0]['name']);
        $this->assertSame('Lamp', $data[1]['name']);
        $this->assertSame(['-price'], $payload['meta']['sort']);
    }

    public function testIndexFilterUnknownFieldReturns400(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products', ['filter' => ['secret' => 'x']]);
        $this->assertSame(400, $response->statusCode());
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertFalse($payload['success']);
        $this->assertSame('invalid_query', $payload['meta']['error_code']);
    }

    public function testIndexSortUnknownFieldReturns400(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products', ['sort' => 'secret']);
        $this->assertSame(400, $response->statusCode());
    }

    public function testIndexIncludeEmbedsRelation(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products', [
            'filter' => ['status' => 'draft'],
            'include' => 'category',
        ]);
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];
        $this->assertSame('Gadgets', $data[0]['category']['name']);
        $this->assertSame(['category'], $payload['meta']['includes']);
    }

    public function testIndexIncludeUnknownReturns400(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products', ['include' => 'owner']);
        $this->assertSame(400, $response->statusCode());
    }

    public function testIndexSparseFields(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products', ['fields' => 'id,name']);
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];
        $this->assertSame(['id', 'name'], array_keys($data[0]));
        $this->assertSame(['id', 'name'], $payload['meta']['fields']);
    }

    public function testIndexPaginationSecondPage(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products', ['page' => '2', 'per_page' => '2']);
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];
        $this->assertCount(1, $data);
        $this->assertSame(3, $payload['meta']['total']);
        $this->assertSame(2, $payload['meta']['last_page']);
        $this->assertSame(2, $payload['meta']['page']);
    }

    // =========================================================================
    // Show / store / update / destroy
    // =========================================================================

    public function testShowReturnsModel(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products/1');
        $this->assertSame(200, $response->statusCode());
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertSame('Hammer', $payload['data']['name']);
    }

    public function testShowMissingThrowsNotFound(): void
    {
        $this->allowAll();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $this->expectException(ModelNotFoundException::class);
        $this->dispatch('GET', '/api_crud_products/999');
    }

    public function testStoreCreatesModel(): void
    {
        $this->allowAll();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('POST', '/api_crud_products', [], [
            'name' => 'Saw', 'price' => 55, 'status' => 'active', 'category_id' => 1,
        ]);
        $this->assertSame(201, $response->statusCode());
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertSame('Saw', $payload['data']['name']);
        $this->assertSame(1, ApiCrudProduct::count());
    }

    public function testStoreDiscardsNonFillableFields(): void
    {
        $this->allowAll();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('POST', '/api_crud_products', [], [
            'name' => 'Saw', 'price' => 55, 'is_admin' => true,
        ]);
        $this->assertSame(201, $response->statusCode());
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertArrayNotHasKey('is_admin', $payload['data']);
    }

    public function testStoreWithFormRequestValidationFails(): void
    {
        $this->allowAll();
        $options = $this->options;
        $options['request'] = ApiCrudProductRequest::class;
        $this->router->apiResource(ApiCrudProduct::class, $options);

        $this->expectException(ValidationException::class);
        $this->dispatch('POST', '/api_crud_products', [], ['price' => 55]);
    }

    public function testStoreWithFormRequestValidationPasses(): void
    {
        $this->allowAll();
        $options = $this->options;
        $options['request'] = ApiCrudProductRequest::class;
        $this->router->apiResource(ApiCrudProduct::class, $options);

        $response = $this->dispatch('POST', '/api_crud_products', [], [
            'name' => 'Saw', 'price' => 55, 'status' => 'active',
        ]);
        $this->assertSame(201, $response->statusCode());
        $this->assertSame(1, ApiCrudProduct::count());
    }

    public function testUpdateChangesModel(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('PUT', '/api_crud_products/1', [], ['price' => 30]);
        $this->assertSame(200, $response->statusCode());
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertSame(30.0, (float) $payload['data']['price']);
        $model = ApiCrudProduct::find(1);
        $this->assertNotNull($model);
        $this->assertSame(30.0, (float) $model->toArray()['price']);
    }

    public function testDestroyDeletesModel(): void
    {
        $this->allowAll();
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('DELETE', '/api_crud_products/1');
        $this->assertSame(204, $response->statusCode());
        $this->assertNull(ApiCrudProduct::find(1));
    }

    // =========================================================================
    // Policy (default DENY)
    // =========================================================================

    public function testDefaultDenyWithoutAbility(): void
    {
        // No Gate ability defined at all: index must deny (403 via exception
        // at Router level; App maps AuthorizationException to 403).
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $this->expectException(AuthorizationException::class);
        $this->dispatch('GET', '/api_crud_products');
    }

    public function testCustomAbilityOverride(): void
    {
        Gate::define('shop.browse', static fn (): bool => true);
        $this->seed();
        $options = $this->options;
        $options['abilities'] = ['index' => 'shop.browse'];
        $this->router->apiResource(ApiCrudProduct::class, $options);

        // index allowed via custom ability; show still denied (default ability).
        $response = $this->dispatch('GET', '/api_crud_products');
        $this->assertSame(200, $response->statusCode());

        $this->expectException(AuthorizationException::class);
        $this->dispatch('GET', '/api_crud_products/1');
    }

    public function testGateBeforeHookCanGrant(): void
    {
        Gate::before(static fn (): bool => true);
        $this->seed();
        $this->router->apiResource(ApiCrudProduct::class, $this->options);

        $response = $this->dispatch('GET', '/api_crud_products');
        $this->assertSame(200, $response->statusCode());
    }

    // =========================================================================
    // Custom Resource
    // =========================================================================

    public function testCustomResourceShapesOutput(): void
    {
        $this->allowAll();
        $this->seed();
        $options = $this->options;
        $options['resource'] = ApiCrudProductResource::class;
        // 'label' is a computed resource field: it must be whitelisted like
        // any other output field before ?fields= may select it.
        $options['fields'] = [...$this->options['fields'], 'label'];
        $this->router->apiResource(ApiCrudProduct::class, $options);

        $response = $this->dispatch('GET', '/api_crud_products/1');
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        $this->assertSame('HAMMER', $payload['data']['label']);
        $this->assertSame('Hammer', $payload['data']['name']);
    }

    public function testCustomResourceWithSparseFields(): void
    {
        $this->allowAll();
        $this->seed();
        $options = $this->options;
        $options['resource'] = ApiCrudProductResource::class;
        // 'label' is a computed resource field: it must be whitelisted like
        // any other output field before ?fields= may select it.
        $options['fields'] = [...$this->options['fields'], 'label'];
        $this->router->apiResource(ApiCrudProduct::class, $options);

        $response = $this->dispatch('GET', '/api_crud_products', ['fields' => 'id,label']);
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        /** @var array<int, array<string, mixed>> $data */
        $data = $payload['data'];
        $this->assertSame(['id', 'label'], array_keys($data[0]));
    }
}
