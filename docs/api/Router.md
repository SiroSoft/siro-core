# Router API Reference

## Overview

The Router handles HTTP request routing to controllers and closures with middleware support.

---

## Basic Usage

### Define Routes

```php
use Siro\Core\Route;

// GET request
Route::get('/users', [UserController::class, 'index']);

// POST request
Route::post('/users', [UserController::class, 'store']);

// PUT request
Route::put('/users/{id}', [UserController::class, 'update']);

// DELETE request
Route::delete('/users/{id}', [UserController::class, 'destroy']);

// Any HTTP method
Route::any('/webhook', [WebhookController::class, 'handle']);
```

### Route Parameters

```php
// Required parameter
Route::get('/users/{id}', function ($id) {
    return ['id' => $id];
});

// Multiple parameters
Route::get('/posts/{postId}/comments/{commentId}', function ($postId, $commentId) {
    return ['post' => $postId, 'comment' => $commentId];
});

// Optional parameter
Route::get('/users/{id?}', function ($id = null) {
    return ['id' => $id ?? 'all'];
});
```

### Route Constraints

```php
// Numeric ID only
Route::get('/users/{id}', [UserController::class, 'show'])
    ->where('id', '/^\d+$/');

// UUID format
Route::get('/posts/{uuid}', [PostController::class, 'show'])
    ->where('uuid', '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

// Multiple constraints
Route::get('/users/{id}/posts/{slug}', [UserController::class, 'posts'])
    ->where(['id' => '/^\d+$/', 'slug' => '/^[a-z0-9-]+$/']);
```

---

## Route Groups

### Group with Common Prefix

```php
Route::group(['prefix' => 'api/v1'], function () {
    Route::get('/users', [V1\UserController::class, 'index']);
    Route::post('/users', [V1\UserController::class, 'store']);
});
// → GET /api/v1/users
// → POST /api/v1/users
```

### Group with Middleware

```php
Route::group(['middleware' => [AuthMiddleware::class]], function () {
    Route::get('/profile', [UserController::class, 'profile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);
});
```

### Nested Groups

```php
Route::group(['prefix' => 'api'], function () {
    Route::group(['prefix' => 'v1'], function () {
        Route::get('/users', [V1\UserController::class, 'index']);
    });
    
    Route::group(['prefix' => 'v2'], function () {
        Route::get('/users', [V2\UserController::class, 'index']);
    });
});
```

---

## Middleware

### Single Middleware

```php
Route::get('/admin/dashboard', [AdminController::class, 'index'])
    ->middleware([AuthMiddleware::class]);
```

### Multiple Middleware

```php
Route::post('/users', [UserController::class, 'store'])
    ->middleware([AuthMiddleware::class, ThrottleMiddleware::class]);
```

### Role-Based Middleware

```php
// Require admin role
Route::get('/admin/users', [AdminController::class, 'index'])
    ->middleware(['auth:admin']);

// Require user or admin role
Route::post('/posts', [PostController::class, 'store'])
    ->middleware(['auth:user,admin']);
```

### Global Middleware

```php
// In App.php or bootstrap file
$app->middleware([
    CorsMiddleware::class,
    JsonMiddleware::class,
]);
```

---

## Rate Limiting

### Throttle Routes

```php
// 5 requests per minute
Route::post('/auth/login', [AuthController::class, 'login'])
    ->throttle(5, 1);

// 60 requests per hour
Route::get('/api/data', [DataController::class, 'index'])
    ->throttle(60, 60);

// 100 requests per day
Route::post('/api/upload', [UploadController::class, 'upload'])
    ->throttle(100, 1440);
```

---

## API Versioning

### Version Groups

```php
$router->version(1, function ($router) {
    $router->get('/users', [V1\UserController::class, 'index']);
    $router->post('/posts', [V1\PostController::class, 'store']);
});
// → GET /api/v1/users
// → POST /api/v1/posts

$router->version(2, function ($router) {
    $router->get('/users', [V2\UserController::class, 'index']);
    $router->post('/posts', [V2\PostController::class, 'store']);
});
// → GET /api/v2/users
// → POST /api/v2/posts
```

---

## Route Caching

Routes are automatically cached for performance. No manual action needed.

**Cache location:** `storage/cache/routes.php`

**Clear cache (if needed):**
```bash
rm storage/cache/routes.php
```

---

## Listing Routes

### CLI Command

```bash
php siro route:list
```

**Output:**
```
+--------+------------------+------------------------------------------+------------+
| Method | Path             | Handler                                  | Middleware |
+--------+------------------+------------------------------------------+------------+
| GET    | /                | Closure                                  |            |
| GET    | /api/users       | UserController@index                     | auth       |
| POST   | /api/users       | UserController@store                     | auth       |
| PUT    | /api/users/{id}  | UserController@update                    | auth       |
| DELETE | /api/users/{id}  | UserController@destroy                   | auth       |
+--------+------------------+------------------------------------------+------------+
```

---

## Advanced Features

### Fallback Route

```php
Route::fallback(function () {
    return Response::json([
        'error' => 'Route not found'
    ], 404);
});
```

### OPTIONS Handling

Framework automatically handles CORS preflight requests. No configuration needed.

### Route Naming (Future)

```php
// Planned feature
Route::get('/users', [UserController::class, 'index'])->name('users.index');
$url = route('users.index'); // /users
```

---

## Error Handling

### Custom 404 Handler

```php
Route::fallback(function () {
    return Response::json([
        'success' => false,
        'error' => 'Resource not found',
        'code' => 404
    ], 404);
});
```

### Method Not Allowed

Framework automatically returns 405 status for unsupported methods.

---

## Best Practices

### 1. Use Controllers Over Closures

```php
// ❌ Bad - inline closure
Route::get('/users', function () {
    return User::all();
});

// ✅ Good - controller method
Route::get('/users', [UserController::class, 'index']);
```

### 2. Group Related Routes

```php
Route::group(['prefix' => 'api/v1', 'middleware' => ['auth']], function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);
});
```

### 3. Add Rate Limiting to Sensitive Endpoints

```php
Route::post('/auth/login', [AuthController::class, 'login'])
    ->throttle(5, 1); // Prevent brute force
```

### 4. Use Route Constraints

```php
Route::get('/users/{id}', [UserController::class, 'show'])
    ->where('id', '/^\d+$/'); // Only numeric IDs
```

### 5. Document Your Routes

```php
/**
 * Get all users
 * 
 * @GET /api/users
 * @Middleware auth
 * @Response 200 { "data": [...] }
 */
Route::get('/users', [UserController::class, 'index']);
```

---

## Examples

### RESTful Resource Routes

```php
// Manual RESTful routes
Route::get('/posts', [PostController::class, 'index']);      // List
Route::get('/posts/{id}', [PostController::class, 'show']);  // Show
Route::post('/posts', [PostController::class, 'store']);     // Create
Route::put('/posts/{id}', [PostController::class, 'update']); // Update
Route::delete('/posts/{id}', [PostController::class, 'destroy']); // Delete

// Or use make:crud command
// php siro make:crud posts
```

### Authentication Routes

```php
Route::group(['prefix' => 'auth'], function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])
        ->throttle(5, 1);
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware(['auth']);
    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->middleware(['auth']);
    Route::get('/me', [AuthController::class, 'me'])
        ->middleware(['auth']);
});
```

### Webhook Routes

```php
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
Route::post('/webhooks/github', [GithubWebhookController::class, 'handle']);
```

---

## Performance Tips

1. **Use static routes when possible** - Faster than parameterized routes
2. **Group routes with common middleware** - Reduces middleware evaluation
3. **Add constraints to parameters** - Faster route matching
4. **Avoid too many routes** - Consider versioning or modularization
5. **Cache is automatic** - No manual intervention needed

---

## Troubleshooting

### Route Not Found

**Check:**
1. Route is defined in `routes/api.php` or `routes/web.php`
2. HTTP method matches (GET vs POST)
3. URL path is correct (case-sensitive)
4. No typos in route definition

### Middleware Not Running

**Check:**
1. Middleware class exists and is autoloaded
2. Middleware implements proper interface
3. Middleware is added to route or group
4. Middleware order is correct

### Parameter Not Passed

**Check:**
1. Parameter name matches in route and handler
2. Route constraint allows the value
3. Parameter is required/optional as expected

---

## See Also

- [Middleware Guide](../MIDDLEWARE.md)
- [Controller Best Practices](CONTROLLER.md)
- [API Versioning Strategy](../ARCHITECTURE.md#adr-xxx-api-versioning)
