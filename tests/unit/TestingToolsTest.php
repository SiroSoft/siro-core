<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\Testing\Factory;
use Siro\Core\Testing\Faker;
use Siro\Core\Testing\TestClient;
use Siro\Core\Tests\TestCase;

/**
 * Testing toolkit: Faker, Factory, TestClient/TestResponse.
 */
final class TestingToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Database::execute('CREATE TABLE tool_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, role TEXT DEFAULT "user", created_at TEXT, updated_at TEXT)');
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testFakerSeededDeterminism(): void
    {
        Faker::seed(42);
        $a = Faker::vnFullName() . Faker::vnPhone() . Faker::email();
        Faker::seed(42);
        $b = Faker::vnFullName() . Faker::vnPhone() . Faker::email();
        $this->assertSame($a, $b);
    }

    public function testFakerFormats(): void
    {
        Faker::seed(7);
        $this->assertMatchesRegularExpression('/^\S+ \S+ \S+$/u', Faker::vnFullName());
        $this->assertMatchesRegularExpression('/^0\d{9}$/', Faker::vnPhone());
        $this->assertMatchesRegularExpression('/^[^@]+@example\.com$/', Faker::email('Nguyen Van A'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            Faker::uuid()
        );
        $this->assertGreaterThanOrEqual(1, Faker::intBetween(1, 10));
        $this->assertLessThanOrEqual(10, Faker::intBetween(1, 10));
        $this->assertSame('x', Faker::pick(['x']));
        $this->assertNull(Faker::pick([]));
    }

    public function testFactoryMakeAndCreate(): void
    {
        $attrs = ToolUserFactory::new()->make();
        $this->assertIsArray($attrs);
        $this->assertArrayHasKey('email', $attrs);

        $overridden = ToolUserFactory::new()->with(['role' => 'admin'])->raw();
        $this->assertSame('admin', $overridden['role']);

        $user = ToolUserFactory::new()->create();
        $this->assertInstanceOf(ToolUser::class, $user);
        $this->assertNotNull($user->getAttribute('id'));

        $users = ToolUserFactory::new()->count(3)->create();
        $this->assertCount(3, $users);
        $this->assertCount(4, Database::table('tool_users')->get());
    }

    public function testFactoryState(): void
    {
        $attrs = ToolUserFactory::new()
            ->state(static fn (array $a): array => [...$a, 'role' => 'editor'])
            ->raw();
        $this->assertSame('editor', $attrs['role']);
    }

    public function testClientCrudFlow(): void
    {
        $router = new Router();
        $router->get('/api/tools', static fn (): Response => Response::success(['items' => []]));
        $router->post('/api/tools', static function (Request $request): Response {
            $created = $request->validate(['name' => 'required|string']);
            return Response::created(['name' => $created['name']], 'Created');
        });
        $router->get('/api/me', static fn (Request $request): Response => Response::success(['role' => $request->user()['role'] ?? 'guest']));

        $client = TestClient::through($router);
        $client->get('/api/tools')->assertOk()->assertJsonCount(0, 'data.items');

        $client->postJson('/api/tools', ['name' => 'Siro'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Siro');

        $client->actingAs(['id' => 1, 'role' => 'admin'])
            ->get('/api/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $client->postJson('/api/tools', [])->assertUnprocessable();
        $client->get('/api/missing')->assertNotFound();
    }

    public function testClientMissingRoute(): void
    {
        $router = new Router();
        TestClient::through($router)->delete('/api/gone')->assertNotFound();
    }
}

final class ToolUser extends Model
{
    protected string $table = 'tool_users';
    protected array $fillable = ['name', 'email', 'role'];
}

final class ToolUserFactory extends Factory
{
    protected string $model = ToolUser::class;

    public function definition(): array
    {
        return [
            'name' => Faker::vnFullName(),
            'email' => Faker::email(),
            'role' => 'user',
        ];
    }
}
