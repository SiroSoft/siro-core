# Testing Guide

## Global-state discipline (mandatory)

Siro core is dependency-free and pragmatic: several subsystems keep process
statics for speed (routing tables, caches, metrics, event listeners). Any test
that touches global state **must reset it in `tearDown()`**, otherwise state
leaks across cases ("green alone, red together").

### Reset map

| Touched global | Reset call in tearDown |
|---|---|
| Event listeners | `Event::flush()` |
| Request body cache | `Request::resetCache()` |
| Router aliases / priorities / reflection cache | `Router::resetStatic()` |
| Named routes | `Route::resetNamedRoutes()` |
| Validator custom rules / messages | `Validator::resetCustomizations()` |
| Metrics series | `Metrics::reset()` |
| API version registry | `VersionMiddleware::reset()` |
| Mail fake / provider | `Mail::reset()` |
| Gate abilities / acting user | `Gate::reset()` |
| Container bindings / values | `$container->clear()` (or `Container::getInstance()->clear()`) |
| Queue fake | `Queue::reset()` |
| Cache instance | `Cache::reset()` |
| Env overrides | `Env::reset()` |
| Request trace | `TraceData::reset()` |
| `$_SERVER` / `$_GET` / `$_POST` / `$_FILES` | Snapshot in `setUp()`, restore in `tearDown()` |

### Template

```php
protected function setUp(): void
{
    parent::setUp();
    $this->origServer = $_SERVER;
    Event::flush();
    Request::resetCache();
}

protected function tearDown(): void
{
    $_SERVER = $this->origServer;
    Event::flush();
    Request::resetCache();
    Validator::resetCustomizations();
    parent::tearDown();
}
```

### Anti-flake gate

The full suite must pass **twice in a row** (`composer test && composer test`)
before release. A second-run-only failure means a missing reset — find the
leaking static and add it to the map above.

## Toolkit (v1.3+)

### Seeded data: Faker + Factory

```php
Faker::seed(42); // deterministic runs
$name = Faker::vnFullName();
$phone = Faker::vnPhone();

final class UserFactory extends Factory
{
    protected string $model = User::class;

    public function definition(): array
    {
        return ['name' => Faker::vnFullName(), 'email' => Faker::email()];
    }
}

$user = UserFactory::new()->create();
$admins = UserFactory::new()->count(3)->with(['role' => 'admin'])->create();
$attrs = UserFactory::new()->make(); // attributes only, no DB
```

`make:factory User` scaffolds a factory extending this base. Factories need a
database — pair with in-memory SQLite (`createInMemorySqlite()`).

### Server-less HTTP tests: TestClient

```php
$client = TestClient::through($router)->actingAs(['id' => 1, 'role' => 'admin']);
$client->postJson('/api/products', ['name' => 'Siro'])
    ->assertCreated()
    ->assertJsonPath('data.name', 'Siro');
```

Framework exceptions (validation 422, auth 403, not-found 404, malformed
body 400) convert to responses exactly like `App::run()`, so HTTP-level
assertions (`assertOk`, `assertJsonPath`, `assertJsonCount`, …) work without
a web server.

### Fakes with assertions

- `Mail::fake()` + `Mail::assertSent($subject)` / `assertSentTo($address)`
- `Queue::fake()` + `Queue::assertPushed($job)` / `assertNotPushed($job)`
- `Gate::actingAs($user)` for authorization paths (reset with `Gate::reset()`)
