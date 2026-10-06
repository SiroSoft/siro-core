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
