
# Siro Core Framework v0.7.10

**Siro API Framework Core** - Lightweight PHP Micro-Framework Core Library

## Description

This is the core library for Siro API Framework. It provides essential components for building REST APIs:

- ⚡ **Router & Request/Response** - Fast routing with middleware support & auto OPTIONS handling
- 🗄️ **Database QueryBuilder** - PDO-based query builder with automatic caching
- 🎯 **Model Layer** - ORM-like experience with relationships, scopes, and soft deletes
- 🔐 **JWT Authentication** - Built-in JWT token generation and verification
- ✅ **Smart Validation** - Automatic 422 responses with extended rules (unique, exists, confirmed, in)
- 💾 **Cache System** - File and Redis cache drivers
- 🛠️ **Console Commands** - CLI tools for migrations, scaffolding, and route listing
- 📦 **Resource Transformation** - Auto-mapping helpers for API responses
- 🔤 **Typed Input Helpers** - Type-safe request data handling
- 📁 **File Upload** - Convenient file upload handling with validation
- ✅ **Comprehensive Testing** - 142 unit tests with PHPUnit infrastructure (v0.7.7)
- 🌱 **Database Seeders** - Built-in seeder system for test data (v0.7.8)
- 📊 **Enhanced QueryBuilder** - whereBetween, whereNull, pluck, chunk, exists, inRandomOrder (v0.7.8)
- ⚡ **Model Shortcuts** - findOrFail, firstOrCreate, updateOrCreate (v0.7.8)
- 🎨 **Fluent Response** - Chainable header() and withHeaders() methods (v0.7.8)
- 🔐 **Complete Auth System** - JWT refresh tokens, email verification, password reset (v0.7.9)
- 🛡️ **Security Hardening** - Rate limiting per route, CSRF protection middleware (v0.7.9)
- ⚙️ **Performance Optimization** - Config caching, env validation, slow query logging (v0.7.10)

## Installation

```bash
composer require sirosoft/core
```

## Requirements

- PHP >= 8.2
- PDO extension
- JSON extension
- Mbstring extension

## Quick Start

### Basic Application Setup

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Siro\Core\App;
use Siro\Core\Route;
use Siro\Core\Response;

$app = new App();

// Define routes
Route::get('/', function() {
    return Response::json([
        'message' => 'Welcome to Siro API',
        'version' => '0.7.6'
    ]);
});

Route::get('/users', function() {
    $users = \Siro\Core\DB::table('users')->get();
    return Response::json($users);
});

// Run the application
$app->run();
```

### Database Operations

```php
use Siro\Core\DB;

// Select with caching
$users = DB::table('users')
    ->select(['id', 'name', 'email'])
    ->where('active', 1)
    ->cache(60)  // Cache for 60 seconds
    ->get();

// Insert
$id = DB::table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// Update
DB::table('users')
    ->where('id', $id)
    ->update(['name' => 'Jane Doe']);

// Delete
DB::table('users')
    ->where('id', $id)
    ->delete();
```

### Model Layer (NEW in v0.7.5)

Create models that extend `Siro\Core\Model`:

```php
namespace App\Models;

use Siro\Core\Model;

final class User extends Model
{
    protected string $table = 'users';
    
    protected array $hidden = ['password'];
    
    protected array $casts = [
        'id' => 'int',
        'status' => 'int',
    ];
    
    protected array $fillable = ['name', 'email', 'password', 'status'];
}
```

Use the model:

```php
use App\Models\User;

// Find by ID
$user = User::find(1);

// Query builder integration
$users = User::where('status', 1)
    ->orderBy('name')
    ->get();

// Create
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => bcrypt('secret'),
]);

// Update
$user->update(['name' => 'Jane Doe']);

// Delete
$user->delete();

// Pagination
$result = User::query()->paginate(20, $page);
```

### Model Relationships (NEW in v0.7.6)

Define relationships in your models:

```php
namespace App\Models;

use Siro\Core\Model;

final class Post extends Model
{
    protected string $table = 'posts';
    
    // One-to-Many: A post has many comments
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id', 'id');
    }
    
    // Many-to-One: A post belongs to a user
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
```

Use relationships:

```php
// Eager load relationships
$post = Post::with('author', 'comments')->find(1);

// Access related data
$authorName = $post->author->name;
$comments = $post->comments;  // Collection of Comment models

// Query through relationships
$userPosts = User::find(1)->posts()->where('status', 'published')->get();
```

### Soft Deletes (NEW in v0.7.6)

Enable soft deletes by using the trait:

```php
namespace App\Models;

use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

final class User extends Model
{
    use SoftDeletes;
    
    protected string $table = 'users';
}
```

Soft delete operations:

```php
// Soft delete (sets deleted_at timestamp)
$user->delete();

// Force delete (permanent removal)
$user->forceDelete();

// Include trashed records
User::withTrashed()->get();

// Only trashed records
User::onlyTrashed()->get();

// Restore a soft-deleted record
User::withTrashed()->find(1)->restore();
```

### JWT Authentication

```php
use Siro\Core\Auth\JWT;

// Generate token
$token = JWT::generate([
    'user_id' => 1,
    'role' => 'admin'
]);

// Verify token
try {
    $payload = JWT::verify($token);
    echo "User ID: " . $payload['user_id'];
} catch (Exception $e) {
    echo "Invalid token: " . $e->getMessage();
}
```

### Console Commands

Create custom CLI commands:

```php
// In your console file
require_once __DIR__ . '/vendor/autoload.php';

use Siro\Core\Console;

Console::command('greet', function($name = 'World') {
    echo "Hello, {$name}!\n";
});

Console::run();
```

Run from terminal:
```bash
php console greet John
```

**Available Commands (v0.7.10):**
```bash
php siro make:model User          # Generate model scaffolding
php siro make:api users           # Generate CRUD API
php siro make:controller UserController
php siro make:migration create_posts_table
php siro make:resource UserResource
php siro make:seeder UserSeeder   # Generate seeder (NEW in v0.7.8)
php siro make:auth                # Generate full auth system (NEW in v0.7.9)
php siro migrate                  # Run migrations
php siro migrate:rollback         # Rollback migrations
php siro migrate:status           # Check migration status (table format)
php siro route:list               # List all routes (table format)
php siro db:seed                  # Run all seeders (NEW in v0.7.8)
php siro db:seed UserSeeder       # Run specific seeder
php siro config:cache             # Cache config for faster boot (NEW in v0.7.10)
php siro env:check                # Validate environment config (NEW in v0.7.10)
php siro optimize                 # Optimize for production (NEW in v0.7.10)
php siro serve                    # Start development server
php siro key:generate             # Generate APP_KEY
php siro doctor                   # Check system health
```

## Features

### Router

Supports GET, POST, PUT, DELETE, PATCH methods with route parameters:

```php
Route::get('/users/{id}', function($id) {
    return Response::json(['id' => $id]);
});

Route::post('/users', function() {
    $data = request()->all();
    return Response::json(['created' => $data], 201);
});
```

### Middleware

```php
// Global middleware
$app->middleware([\App\Middleware\AuthMiddleware::class]);

// Route-specific middleware
Route::get('/admin', function() {
    return Response::json(['message' => 'Admin area']);
})->middleware([\App\Middleware\AdminMiddleware::class]);
```

### Request Validation (NEW in v0.7.5)

Automatic validation with 422 responses:

```php
use Siro\Core\Request;

public function store(Request $request)
{
    // Throws ValidationException automatically on failure
    $validated = $request->validate([
        'email' => 'required|email|unique:users,email',
        'password' => 'required|min:8|confirmed',
        'status' => 'required|in:active,inactive,pending',
    ]);
    
    // If we get here, validation passed
    User::create($validated);
}
```

**Extended Validation Rules:**
- `unique:table,column` - Check value doesn't exist
- `exists:table,column` - Check value exists
- `confirmed` - Check if field matches `{field}_confirmation`
- `in:a,b,c` - Check if value is in allowed list

### Typed Input Helpers (NEW in v0.7.5)

Type-safe request data handling:

```php
$id = $request->int('id');              // Integer
$name = $request->string('name');       // String
$active = $request->bool('active');     // Boolean
$items = $request->array('items');      // Array
$price = $request->float('price');      // Float
$page = $request->queryInt('page', 1);  // Query param as int
$search = $request->queryString('q');   // Query param as string
```

### Enhanced Request Methods (NEW in v0.7.6)

Additional convenient methods:

```php
// Get validated data (throws ValidationException on failure)
$validated = $request->validated([
    'email' => 'required|email',
    'name' => 'required|min:3',
]);

// Get only specific fields from request
$data = $request->only(['name', 'email']);
// Returns: ['name' => 'John', 'email' => 'john@example.com']

// Handle file uploads
$file = $request->file('avatar');
if ($file) {
    $path = $file->store('uploads/avatars');
    $originalName = $file->getClientOriginalName();
    $size = $file->getSize();
}
```

### Caching

Automatic cache invalidation on database mutations:

```php
// Cached query
$users = DB::table('users')->cache(300)->get();

// Cache automatically invalidated on:
DB::table('users')->insert([...]);  // INSERT
DB::table('users')->update([...]);  // UPDATE
DB::table('users')->delete();       // DELETE
```

### Complete Auth System (NEW in v0.7.9)

Generate full authentication system with one command:

```bash
php siro make:auth    # Generate migrations, controllers, routes, models
php siro migrate      # Run migrations
```

**Generated API Endpoints:**
- `POST /auth/register` - User registration
- `POST /auth/login` - Login and get tokens
- `POST /auth/refresh` - Refresh access token using refresh token
- `POST /auth/logout` - Logout and revoke refresh token
- `POST /auth/verify-email` - Verify email address
- `POST /auth/forgot-password` - Request password reset
- `POST /auth/reset-password` - Reset password with token
- `GET /auth/me` - Get current user profile

**JWT with Refresh Tokens:**

```php
use Siro\Core\Auth\JWT;

// Generate access token (1 hour TTL)
$accessToken = JWT::encodeAccess($userId, $tokenVersion);

// Generate refresh token (7 days TTL)
$refreshToken = JWT::encodeRefresh($userId, $tokenVersion);

// Decode and verify token
try {
    $payload = JWT::decode($token);
    echo "User ID: " . $payload['sub'];
} catch (RuntimeException $e) {
    echo "Invalid token: " . $e->getMessage();
}
```

**Email Verification:**

Users receive a verification token via email. Endpoint `/auth/verify-email` validates the token and marks the email as verified.

**Password Reset Flow:**

1. User requests reset: `POST /auth/forgot-password` with email
2. System sends reset token to email
3. User resets password: `POST /auth/reset-password` with token and new password

### Security Hardening (NEW in v0.7.9)

**Rate Limiting per Route:**

```php
use Siro\Core\Route;

// Limit to 5 requests per minute
Route::post('/auth/login', [AuthController::class, 'login'])
    ->throttle(5, 1);

// Limit to 60 requests per hour
Route::post('/api/data', [DataController::class, 'store'])
    ->throttle(60, 60);
```

Rate limit headers are automatically added to responses:
- `X-RateLimit-Limit` - Maximum requests allowed
- `X-RateLimit-Remaining` - Remaining requests
- `X-RateLimit-Reset` - Timestamp when limit resets
- `Retry-After` - Seconds to wait (when limit exceeded)

**CSRF Protection:**

```php
use Siro\Core\Middleware\CsrfMiddleware;

// Add CSRF protection to routes
Route::post('/api/data', [Controller::class, 'store'])
    ->middleware([CsrfMiddleware::class]);

// In HTML forms, include CSRF token:
echo CsrfMiddleware::field(); // Hidden input field
// Or in meta tag:
echo CsrfMiddleware::metaTag(); // Meta tag for JavaScript

// In JavaScript, send token in header:
// fetch('/api/data', {
//     headers: { 'X-CSRF-TOKEN': csrfToken }
// });
```

### Performance Optimization (NEW in v0.7.10)

**Config Caching:**

Cache environment variables and database configuration for faster application boot:

```bash
php siro config:cache    # Cache .env and config/database.php
php siro optimize        # Config cache + composer dump-autoload
```

Cached config is stored in `storage/cache/config.php` and automatically loaded on subsequent requests.

**Environment Validation:**

Validate your environment before deployment:

```bash
php siro env:check
```

Checks:
- ✅ `.env` file exists
- ✅ Required variables set (APP_NAME, APP_ENV, APP_DEBUG, JWT_SECRET, DB_CONNECTION)
- ✅ JWT_SECRET strength (min 32 chars, not a placeholder)
- ✅ APP_DEBUG is false in production
- ✅ PHP extensions loaded (pdo, json, mbstring)
- ✅ Storage directories writable

**Slow Query Logging:**

Automatically log queries that exceed a configurable threshold:

```env
# .env
DB_SLOW_QUERY_THRESHOLD=100  # Log queries slower than 100ms (default)
```

Slow queries are logged to `storage/logs/error.log` with format:
```
Slow query (150.25ms): SELECT * FROM users WHERE email = :email | Bindings: {"email":"test@example.com"}
```

Useful for identifying performance bottlenecks in production.

### Resource Auto-Mapping (NEW in v0.7.6)

Simplify API response formatting:

```php
use Siro\Core\Resource;

// Single resource with auto-mapping from Model
return Response::json(UserResource::make($user));

// Collection with specific fields
return Response::json(UserResource::collectionOf($users, ['id', 'name', 'email']));

// Manual transformation
final class UserResource extends Resource
{
    public function toArray(): array
    {
        return [
            'id' => $this->data['id'],
            'name' => $this->data['name'],
            'email' => $this->data['email'],
            'created_at' => $this->data['created_at'],
        ];
    }
}
```

### Migrations

Create migration files:
```bash
php siro make:migration create_posts_table
```

Run migrations:
```bash
php siro migrate
```

## Configuration

Create a `.env` file in your project root:

```env
APP_NAME=SiroAPI
APP_ENV=local
APP_KEY=base64:your-secret-key-here

DB_CONNECTION=sqlite
DB_DATABASE=./storage/database.sqlite

CACHE_DRIVER=file
CACHE_PREFIX=siro_

JWT_SECRET=your-jwt-secret
JWT_TTL=3600
```

## Documentation

For full documentation and examples, visit:
https://github.com/SiroSoft/SiroPHP

## Testing

```bash
php tests/integration_test.php
```

## License

MIT License - See LICENSE file for details

## Support

- **Issues:** https://github.com/SiroSoft/siro-core/issues
- **Source:** https://github.com/SiroSoft/siro-core
- **Main Repository:** https://github.com/SiroSoft/SiroPHP

## Testing

Siro Core includes comprehensive unit tests using PHPUnit.

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit tests/unit/ValidatorTest.php

# Run with coverage report
./vendor/bin/phpunit --coverage-html coverage
```

### Test Coverage (v0.7.7)

- **Total Tests:** 142 unit tests
- **Components Tested:** Validator, Request, Response, Router, Model, QueryBuilder, Resource
- **Coverage Areas:** Input validation, type-safe helpers, response methods, routing, ORM operations, query building, resource transformation

## Credits

Created and maintained by SiroSoft Team

---

**Version:** 0.7.10  
**Package:** sirosoft/core  
**Type:** library
