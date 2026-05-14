# Router

The Siro Router supports static and dynamic routes, middleware pipelines with onion model, route groups, PHP 8 route attributes, named routes with URL generation, and route caching.

---

## Basic Routing

```php
use Siro\Core\Route;

Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::put('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);
```

Each method returns a `Route` instance for fluent chaining.

---

## Route Parameters

```php
// Single parameter
Route::get('/users/{id}', function (Request $req) {
    return User::find($req->param('id'));
});

// Multiple parameters
Route::get('/posts/{postId}/comments/{commentId}', [PostController::class, 'show']);

// Regex constraints
Route::get('/users/{id}', [UserController::class, 'show'])
    ->where('id', '[0-9]+');

// Multiple constraints at once
Route::get('/users/{id}/posts/{slug}', [UserController::class, 'posts'])
    ->where(['id' => '[0-9]+', 'slug' => '[a-z0-9-]+']);
```

---

## Route Groups

```php
use Siro\Core\Router;

$router = new Router();

// Group with prefix only
$router->group('/api', function (Router $r) {
    $r->get('/users', [UserController::class, 'index']);
    $r->post('/users', [UserController::class, 'store']);
});
// → GET /api/users, POST /api/users

// Group with middleware
$router->group('/admin', [AuthMiddleware::class], function (Router $r) {
    $r->get('/dashboard', [AdminController::class, 'dashboard']);
});

// Group with both prefix and middleware (middleware as third arg)
$router->group('/api', function (Router $r) {
    $r->get('/profile', [ProfileController::class, 'show']);
}, [AuthMiddleware::class]);

// Nested groups — prefix and middleware merge
$router->group('/api', [ThrottleMiddleware::class], function (Router $r) {
    $r->group('/v1', [VersionMiddleware::class], function (Router $r) {
        $r->get('/users', [UserController::class, 'index']);
    });
});
// → GET /api/v1/users
// Middleware pipeline: ThrottleMiddleware → VersionMiddleware → handler
```

---

## Middleware

### Per-Route Middleware

```php
Route::get('/admin/users', [AdminController::class, 'index'])
    ->middleware([AuthMiddleware::class]);

Route::post('/posts', [PostController::class, 'store'])
    ->middleware([AuthMiddleware::class, ThrottleMiddleware::class]);
```

### Middleware with Parameters

```php
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(['auth:admin']);

Route::post('/posts', [PostController::class, 'store'])
    ->middleware(['auth:user,admin']);
```

### Pipeline Execution Order

Middleware executes in an **onion model** — the first middleware in the array wraps the next, which wraps the handler. The pipeline order is:

1. **Group middleware** (outermost, applied first)
2. **Per-route middleware** (inner, applied after group)
3. **Handler** (innermost)

Given this setup:

```php
$router->group('/api', [LogMiddleware::class], function (Router $r) {
    $r->get('/users', [UserController::class, 'index'])
        ->middleware([AuthMiddleware::class]);
});
```

Execution order: `Request → LogMiddleware → AuthMiddleware → handler → AuthMiddleware → LogMiddleware → Response`

### Middleware Aliases

```php
Router::setMiddlewareAliases([
    'auth' => \Siro\Core\Middleware\AuthMiddleware::class,
    'throttle' => \App\Middleware\ThrottleMiddleware::class,
]);

// Use alias in routes
Route::get('/profile', [ProfileController::class, 'show'])
    ->middleware(['auth']);
```

### Fluent Throttle Helper

```php
use Siro\Core\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->throttle(5, 1); // 5 attempts per minute
```

---

## Route Naming (v0.24)

```php
Route::get('/users', [UserController::class, 'index'])
    ->name('users.index');

Route::get('/users/{id}', [UserController::class, 'show'])
    ->name('users.show');
```

### Generate URL from Named Route

```php
use Siro\Core\Route;

// Simple route
$url = Route::url('users.index'); // /users

// Route with parameters
$url = Route::url('users.show', ['id' => 42]); // /users/42

// Returns null if name not found
$url = Route::url('nonexistent'); // null
```

---

## PHP 8 Route Attributes (v0.24)

### The `#[Route]` Attribute

```php
use Siro\Core\RouteAttribute;

#[Route('/api/users', method: 'GET')]
#[Route('/api/users', method: 'POST')]
public function handle(Request $req): array
{
    return User::all();
}
```

The attribute accepts:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `path` | `string` | required | URL path, e.g. `/api/users` |
| `method` | `string\|array` | `'GET'` | HTTP method(s), e.g. `'POST'` or `['GET', 'POST']` |
| `middleware` | `array` | `[]` | Middleware aliases or class names |
| `cacheTtl` | `int` | `0` | Response cache TTL in seconds |

### Registering Attribute Routes

```php
// In routes/api.php
Route::registerAttributes(__DIR__ . '/../app/Controllers');

// With global middleware and prefix
Route::registerAttributes(
    __DIR__ . '/../app/Controllers/Api',
    globalMiddleware: [AuthMiddleware::class],
    prefix: '/api/v1'
);
```

### Full Controller Example

```php
<?php

namespace App\Controllers;

use Siro\Core\RouteAttribute;
use Siro\Core\Request;

class UserController
{
    #[Route('/api/users', method: 'GET', middleware: ['auth'])]
    public function index(Request $req): array
    {
        return ['data' => User::all()];
    }

    #[Route('/api/users', method: 'POST', middleware: ['auth', 'throttle:10,1'])]
    public function store(Request $req): array
    {
        return ['id' => User::create($req->all())];
    }

    #[Route('/api/users/{id}', method: ['GET', 'PUT', 'DELETE'], middleware: ['auth'], cacheTtl: 60)]
    public function showOrUpdate(Request $req): array
    {
        // ...
    }
}
```

The scanner matches `*Controller.php` files, reads the namespace from the file, and registers each `#[Route]` attribute as a route.

---

## Route Caching

```php
use Siro\Core\Router;

$router = new Router();
// ... define routes ...

// Save route cache
$router->saveToCache(__DIR__ . '/../storage/cache/routes.php');

// Load from cache (returns false if cache file missing)
$loaded = $router->loadFromCache(__DIR__ . '/../storage/cache/routes.php');

// Check if routes were loaded from cache
if ($router->isCached()) {
    // Routes are cached
}
```

Caching notes:
- Routes with `Closure` handlers are excluded from cache (cannot be serialized)
- Only `Class@method` or `[Class::class, 'method']` handlers are cached
- Cache file starts with `<?php exit; ?>` to prevent direct access

### Per-Route Response Caching

```php
Route::get('/users', [UserController::class, 'index'])
    ->cache(60); // Cache GET response for 60 seconds
```

Only `GET` routes with `cache_ttl > 0` are cached at the response level.

---

## CORS Preflight Handling

The router automatically handles `OPTIONS` preflight requests. When a request arrives with method `OPTIONS`:

1. The router checks if any route exists for the requested path (any method)
2. If found, it runs the group middleware pipeline (including `CorsMiddleware`) and returns a `204 No Content` with CORS headers
3. If no route matches, returns `404`

```php
// CorsMiddleware handles header injection
$router->middleware([CorsMiddleware::class]);
```

Configuration via `.env`:

```env
CORS_ALLOWED_ORIGINS=*
CORS_ALLOWED_METHODS=GET,POST,PUT,DELETE,OPTIONS
CORS_ALLOWED_HEADERS=Content-Type,Authorization,X-Requested-With
```

---

## Version Middleware

```php
use Siro\Core\Router;
use Siro\Core\Middleware\VersionMiddleware;

$router = new Router();

// Register API versions
VersionMiddleware::register(1);
VersionMiddleware::register(2);

// Define versioned routes
$router->version(1, function (Router $r) {
    $r->get('/users', [V1\UserController::class, 'index']);
});

$router->version(2, function (Router $r) {
    $r->get('/users', [V2\UserController::class, 'index']);
});

// Add version middleware to group
$router->group('/api', [VersionMiddleware::class], function (Router $r) {
    $r->get('/users', [UserController::class, 'index']);
});
```

Clients specify version via `Accept` header: `Accept: application/vnd.siro.v2+json`

The middleware sets `$request->version` and resolves per-version route overrides.

### Version Overrides

```php
VersionMiddleware::override(2, 'GET', '/users', V2UserController::class);
```

---

## Full Example

```php
<?php

use Siro\Core\Route;
use Siro\Core\Router;
use Siro\Core\Middleware\AuthMiddleware;
use Siro\Core\Middleware\CorsMiddleware;
use Siro\Core\Middleware\ThrottleMiddleware;

$router = new Router();
Route::setRouter($router);

$router->group('/api', [CorsMiddleware::class], function (Router $r) {
    // Public routes
    $r->get('/status', fn() => ['status' => 'ok']);

    // Auth routes with rate limiting
    $r->group('/auth', function (Router $r) {
        $r->post('/login', [AuthController::class, 'login'])
            ->throttle(5, 1)
            ->name('auth.login');
        $r->post('/register', [AuthController::class, 'register'])
            ->throttle(3, 60)
            ->name('auth.register');
    });

    // Protected routes
    $r->group('', [AuthMiddleware::class], function (Router $r) {
        $r->get('/profile', [ProfileController::class, 'show'])
            ->cache(30)
            ->name('profile.show');
        $r->put('/profile', [ProfileController::class, 'update'])
            ->name('profile.update');
    });
});
```
