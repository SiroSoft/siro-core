<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Controller;
use Siro\Core\DB;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Middleware\AuditMiddleware;
use Siro\Core\Middleware\MetricsMiddleware;
use Siro\Core\Metrics;
use Siro\Core\Observers\ModelObserver;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Coverage for zero files: Controller, DB, ModelObserver, Audit/Metrics middleware.
 */
final class ControllerZeroMutationTest extends TestCase
{
    private TestController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->controller = new TestController();
        $this->controller->setRequest(new Request('POST', '/api/x', ['page' => '2'], [], ['name' => 'foo']));
        Metrics::init('test', false);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testControllerSuccess(): void
    {
        $r = $this->controller->doSuccess();
        $this->assertSame(200, $r->statusCode());
    }

    public function testControllerErrorAndCreated(): void
    {
        $this->assertSame(400, $this->controller->doError()->statusCode());
        $this->assertSame(201, $this->controller->doCreated()->statusCode());
        $this->assertSame(204, $this->controller->doNoContent()->statusCode());
    }

    public function testControllerPaginated(): void
    {
        $r = $this->controller->doPaginated();
        $this->assertSame(200, $r->statusCode());
    }

    public function testControllerValidateInputQuery(): void
    {
        $this->assertSame('foo', $this->controller->inputName());
        $this->assertSame('2', $this->controller->queryPage());
        $this->assertIsArray($this->controller->allInput());
    }

    public function testControllerValidate(): void
    {
        $this->assertSame(['name' => 'foo'], $this->controller->doValidate());
    }

    public function testControllerUser(): void
    {
        $this->assertNull($this->controller->getUser());
    }

    public function testDbFacade(): void
    {
        $db = DB::connection();
        $this->assertInstanceOf(\PDO::class, $db);
        DB::table('t')->insert(['name' => 'a']);
        $this->assertCount(1, DB::select('SELECT * FROM t'));
        $this->assertSame(1, DB::execute('DELETE FROM t'));
        $raw = DB::raw('NOW()');
        $this->assertInstanceOf(\Siro\Core\DB\RawExpression::class, $raw);
    }

    public function testModelObserverMethods(): void
    {
        $obs = new TestObserver();
        $model = new class extends \Siro\Core\Model {
            protected string $table = 't';
        };
        $obs->saving($model);
        $obs->saved($model);
        $obs->creating($model);
        $obs->created($model);
        $obs->updating($model);
        $obs->updated($model);
        $obs->deleting($model);
        $obs->deleted($model);
        $obs->forceDeleting($model);
        $obs->forceDeleted($model);
        $obs->restored($model);
        $this->assertTrue(true);
    }

    public function testMetricsMiddleware(): void
    {
        $mw = new MetricsMiddleware();
        $resp = $mw->handle(new Request('GET', '/api/users/123'), fn () => Response::success());
        $this->assertSame(200, $resp->statusCode());
    }

    public function testAuditMiddleware(): void
    {
        $mw = new AuditMiddleware();
        $resp = $mw->handle(new Request('POST', '/api/x'), fn () => Response::success(['ok' => 1]));
        $this->assertSame(200, $resp->statusCode());
    }
}

final class TestController extends Controller
{
    public function doSuccess(): Response
    {
        return $this->success(['a' => 1]);
    }

    public function doError(): Response
    {
        return $this->error('bad', 400);
    }

    public function doCreated(): Response
    {
        return $this->created(['id' => 1]);
    }

    public function doNoContent(): Response
    {
        return $this->noContent();
    }

    public function doPaginated(): Response
    {
        return $this->paginated([[1]], ['page' => 1, 'per_page' => 1, 'total' => 1, 'last_page' => 1]);
    }

    public function inputName(): mixed
    {
        return $this->input('name');
    }

    public function queryPage(): mixed
    {
        return $this->query('page');
    }

    public function allInput(): mixed
    {
        return $this->input();
    }

    public function doValidate(): array
    {
        return $this->validate(['name' => 'required|string']);
    }

    public function getUser(): ?array
    {
        return $this->user();
    }
}

final class TestObserver extends ModelObserver
{
    public function restored(\Siro\Core\Model $model): void
    {
    }
}
