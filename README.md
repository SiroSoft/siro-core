# Siro Core Framework v0.8.2

**Siro API Framework Core** - The Fastest PHP Micro-Framework for API Development with Advanced Debugging

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen.svg)](https://php.net)
[![Packagist](https://img.shields.io/packagist/v/sirosoft/core.svg)](https://packagist.org/packages/sirosoft/core)
[![Downloads](https://img.shields.io/packagist/dt/sirosoft/core.svg)](https://packagist.org/packages/sirosoft/core)

---

## 🚀 Why SiroPHP?

**SiroPHP is not just another framework.** It's designed specifically for API developers who value:

- ⚡ **Speed** - <1ms request time, zero dependencies
- 🔍 **Debug Fast** - Trace ID system, request replay, export capabilities
- 🎯 **Ship Fast** - One-command auth, auto API docs, CRUD scaffolding
- 🛡️ **Secure by Default** - Auto sanitization, rate limiting, CSRF protection
- 💡 **Simple** - Read entire framework in one afternoon

> **"The Laravel alternative that you can read in one afternoon and ship an API in one hour."**

---

## ✨ Key Features

### Core Components
- ⚡ **Router & Middleware** - Fast routing with auto OPTIONS handling
- 🗄️ **Database QueryBuilder** - PDO-based with automatic caching
- 🎯 **Model Layer** - ORM-like with relationships, scopes, soft deletes
- 🔐 **JWT Authentication** - Built-in token generation with refresh tokens
- ✅ **Smart Validation** - Automatic 422 responses with extended rules
- 💾 **Cache System** - File and Redis drivers
- 📦 **Resource Transformation** - Auto-mapping for API responses
- 🔤 **Typed Input Helpers** - Type-safe request data handling

### Advanced Debugging (v0.8.0) 🔍
- 🔍 **Trace ID per Request** - Every request gets unique `X-Siro-Trace-Id`
- 📋 **Request/Response Capture** - Full context including bodies (sanitized)
- 🔄 **Request Replay** - `php siro log:replay <id>` generates curl command
- 📤 **Export Traces** - `php siro log:export --format=json|csv`
- 🔎 **Smart Filtering** - Filter by status, method, slow requests
- 📊 **SQL Query Logging** - Capture all queries with bindings and timing
- 🧹 **Auto Cleanup** - Log rotation (50MB) + retention (30 days)
- 🔒 **Credential Sanitization** - Passwords/tokens auto [REDACTED]

### Developer Experience
- 🛠️ **Console Commands** - CLI tools for migrations, scaffolding, debugging
- 🌱 **Database Seeders** - Built-in seeder system
- 📝 **Migration System** - Schema versioning and rollback
- 🎨 **Fluent Response** - Chainable header() and withHeaders() methods
- 📁 **File Upload** - Convenient upload handling with validation

### Security & Performance
- 🛡️ **Rate Limiting** - Per-route throttling with configurable limits
- 🔐 **CSRF Protection** - Built-in middleware for form protection
- ⚙️ **Config Caching** - Cache environment for faster boot
- 📈 **Slow Query Detection** - Auto-log queries exceeding threshold
- ✅ **Environment Validation** - Pre-deployment checks

---

## 📦 Installation

```bash
composer require sirosoft/core
```

### Requirements

- PHP >= 8.2
- PDO extension
- JSON extension
- Mbstring extension

---

## 🎯 Quick Start

### Basic Application

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
        'version' => '0.8.2'
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

---

## 🔍 Advanced Debugging System (v0.8.0)

### Trace ID System

Every request automatically includes a unique trace ID:

```http
HTTP/1.1 200 OK
X-Siro-Trace-Id: siro_a1b2c3d4e5f6g7h8
Content-Type: application/json

{"success": true, "data": {...}}
```

### View Trace Details

```bash
# View specific trace
php siro log:trace siro_a1b2c3d4e5f6g7h8

# Output:
========================================================
  Trace: siro_a1b2c3d4e5f6g7h8
--------------------------------------------------------
  Time:    2026-04-29 10:32:15
  Method:  POST /api/auth/login
  Status:  200 (45.23ms)
  IP:      192.168.1.100
  Host:    localhost:8080
  Memory:  2.5MB

  Request Body:
    {"email":"test@test.com","password":"[REDACTED]"}

  Response Body:
    {"success":true,"message":"Login successful",...}

  Auth: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6Ik...

  SQL Queries (2):
    1. SELECT * FROM users WHERE email=? LIMIT 1 [1 rows, 0.32ms]
    2. INSERT INTO sessions (...) VALUES (...) [1 rows, 1.21ms]

  Replay: php siro log:replay siro_a1b2c3d4e5f6g7h8
========================================================
```

### Filter Traces

```bash
# Filter by status code
php siro log:trace --status=500

# Filter by HTTP method
php siro log:trace --method=POST

# Show only slow requests (>100ms)
php siro log:trace --slow

# Custom limit
php siro log:trace --limit=50

# Combine filters
php siro log:trace --status=500 --method=POST --slow
```

### Replay Requests

Generate exact curl command to reproduce any request:

```bash
# Generate curl command
php siro log:replay siro_a1b2c3d4e5f6g7h8

# Output:
curl -X POST 'http://localhost:8080/api/auth/login' \
  -H 'Authorization: Bearer eyJ...' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"email":"test@test.com","password":"secret123"}'

# Alternative: httpie format
php siro log:replay siro_a1b2c3d4e5f6g7h8 --format=httpie
```

### Export Traces

```bash
# Export to JSON
php siro log:export --format=json --output=traces.json

# Export to CSV
php siro log:export --format=csv --output=traces.csv

# Export errors only
php siro log:export --status=500 --format=json --output=errors.json

# Export slow requests
php siro log:export --slow --format=csv --output=slow.csv

# Export last 7 days
php siro log:export --days=7 --format=json --output=week.json
```

### Configuration

```env
# .env

# Log retention (days)
LOG_RETENTION_DAYS=30

# Slow query threshold (milliseconds)
DB_SLOW_QUERY_THRESHOLD=100
```

---

## 🎯 Model Layer

### Create Model

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

### Use Model

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
$result = User::paginate(20, $page);
// Returns: ['data' => [...], 'meta' => ['page' => 1, 'per_page' => 20, ...]]
```

### Model Relationships

```php
namespace App\Models;

use Siro\Core\Model;

final class Post extends Model
{
    protected string $table = 'posts';
    
    // One-to-Many
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id', 'id');
    }
    
    // Many-to-One
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

// Eager load relationships
$post = Post::with('author', 'comments')->find(1);

// Access related data
$authorName = $post->author->name;
$comments = $post->comments;
```

### Soft Deletes

```php
namespace App\Models;

use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

final class User extends Model
{
    use SoftDeletes;
    
    protected string $table = 'users';
}

// Soft delete (sets deleted_at timestamp)
$user->delete();

// Force delete (permanent removal)
$user->forceDelete();

// Include trashed records
User::withTrashed()->get();

// Only trashed records
User::onlyTrashed()->get();

// Restore
User::withTrashed()->find(1)->restore();
```

---

## 🔐 Authentication System

### JWT with Refresh Tokens

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

### Complete Auth System

Generate full authentication system with one command:

```bash
php siro make:auth    # Generate migrations, controllers, routes, models
php siro migrate      # Run migrations
```

**Generated API Endpoints:**
- `POST /auth/register` - User registration
- `POST /auth/login` - Login and get tokens
- `POST /auth/refresh` - Refresh access token
- `POST /auth/logout` - Logout and revoke refresh token
- `POST /auth/verify-email` - Verify email address
- `POST /auth/forgot-password` - Request password reset
- `POST /auth/reset-password` - Reset password with token
- `GET /auth/me` - Get current user profile

---

## ✅ Request Validation

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

### Typed Input Helpers

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

---

## 🛡️ Security Features

### Rate Limiting

```php
use Siro\Core\Route;

// Limit to 5 requests per minute
Route::post('/auth/login', [AuthController::class, 'login'])
    ->throttle(5, 1);

// Limit to 60 requests per hour
Route::post('/api/data', [DataController::class, 'store'])
    ->throttle(60, 60);
```

Rate limit headers automatically added:
- `X-RateLimit-Limit` - Maximum requests allowed
- `X-RateLimit-Remaining` - Remaining requests
- `X-RateLimit-Reset` - Timestamp when limit resets
- `Retry-After` - Seconds to wait (when limit exceeded)

### CSRF Protection

```php
use Siro\Core\Middleware\CsrfMiddleware;

// Add CSRF protection to routes
Route::post('/api/data', [Controller::class, 'store'])
    ->middleware([CsrfMiddleware::class]);

// In HTML forms
echo CsrfMiddleware::field(); // Hidden input field
echo CsrfMiddleware::metaTag(); // Meta tag for JavaScript
```

---

## ⚡ Performance Optimization

### Config Caching

```bash
php siro config:cache    # Cache .env and config/database.php
php siro optimize        # Config cache + composer dump-autoload
```

Cached config stored in `storage/cache/config.php`.

### Environment Validation

```bash
php siro env:check
```

Checks:
- ✅ `.env` file exists
- ✅ Required variables set
- ✅ JWT_SECRET strength (min 32 chars)
- ✅ APP_DEBUG is false in production
- ✅ PHP extensions loaded
- ✅ Storage directories writable

### Slow Query Logging

```env
# .env
DB_SLOW_QUERY_THRESHOLD=100  # Log queries slower than 100ms
```

Slow queries logged to `storage/logs/error.log`:
```
Slow query (150.25ms): SELECT * FROM users WHERE email = :email | Bindings: {"email":"test@example.com"}
```

---

## 🛠️ Console Commands

```bash
# Code Generation
php siro make:model User              # Generate model
php siro make:api users               # Generate CRUD API
php siro make:controller UserController
php siro make:migration create_posts_table
php siro make:resource UserResource
php siro make:seeder UserSeeder
php siro make:auth                    # Generate full auth system

# Database
php siro migrate                      # Run migrations
php siro migrate:rollback             # Rollback migrations
php siro migrate:status               # Check migration status
php siro db:seed                      # Run all seeders
php siro db:seed UserSeeder           # Run specific seeder

# Debugging (v0.8.0)
php siro log:trace <trace_id>         # View trace details
php siro log:trace --status=500       # Filter by status
php siro log:trace --method=POST      # Filter by method
php siro log:trace --slow             # Show slow requests
php siro log:replay <trace_id>        # Generate curl command
php siro log:export --format=json     # Export traces

# Performance
php siro config:cache                 # Cache config
php siro env:check                    # Validate environment
php siro optimize                     # Optimize for production

# Utilities
php siro route:list                   # List all routes
php siro serve                        # Start development server
php siro key:generate                 # Generate APP_KEY
php siro doctor                       # Check system health
```

---

## 📊 Comparison with Other Frameworks

| Feature | SiroPHP | Laravel | Django | Express |
|---------|---------|---------|--------|---------|
| **Trace ID per request** | ✅ Built-in | ❌ Telescope only | ❌ Custom | ❌ Custom |
| **Request replay** | ✅ `log:replay` | ❌ None | ❌ None | ❌ None |
| **Export traces** | ✅ `log:export` | ❌ None | ❌ None | ❌ None |
| **CLI trace lookup** | ✅ Built-in | ❌ Web UI only | ❌ None | ❌ None |
| **Zero dependencies** | ✅ Yes | ❌ 200+ packages | ❌ Multiple | ❌ npm modules |
| **Memory per request** | ~2.5 MB | ~80 MB | ~60 MB | ~45 MB |
| **Setup time** | 0 minutes | 30+ minutes | 20+ minutes | 15+ minutes |

**SiroPHP wins on:** Simplicity, speed, debugging capabilities, zero dependencies

---

## 📝 Configuration

Create `.env` file:

```env
APP_NAME=SiroAPI
APP_ENV=local
APP_KEY=base64:your-secret-key-here
APP_DEBUG=true

DB_CONNECTION=sqlite
DB_DATABASE=./storage/database.sqlite

CACHE_DRIVER=file
CACHE_PREFIX=siro_

JWT_SECRET=your-jwt-secret-min-32-chars
JWT_TTL=3600

# Logging (v0.8.0)
LOG_RETENTION_DAYS=30
DB_SLOW_QUERY_THRESHOLD=100
```

---

## 🧪 Testing

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit tests/unit/ValidatorTest.php

# Run with coverage report
./vendor/bin/phpunit --coverage-html coverage
```

---

## 📚 Documentation

For full documentation and examples:
- **Main Repository:** https://github.com/SiroSoft/SiroPHP
- **Core Library:** https://github.com/SiroSoft/siro-core
- **Issues:** https://github.com/SiroSoft/siro-core/issues

---

## 🤝 Contributing

Contributions are welcome! Please read our contributing guidelines before submitting pull requests.

---

## 📄 License

MIT License - See [LICENSE](LICENSE) file for details

---

## 👥 Credits

Created and maintained by **SiroSoft Team**

Special thanks to all contributors who help make SiroPHP better.

---

##  What's New in v0.8.2

### Auto Documentation System 
- 📝 **MakeDocsCommand** - Generate OpenAPI + Swagger UI in one command
- 📂 **Smart Folder Structure** - Organized docs/openapi/, docs/postman/, docs/swagger/
- 🌐 **Live Swagger UI** - Ready-to-serve HTML with CDN links
-  **Postman Integration** - Collections with auto-login pre-request scripts
- ✅ **Validation Parsing** - Extracts $request->validate() rules via regex
-  **Security Detection** - Auto-detects auth middleware, adds Bearer JWT scheme
- 🎯 **Smart Filtering** - Filter by tag, method, path, or flow (auth/crud)
-  **Type Inference** - Converts validation rules to JSON Schema types
- 🔗 **Path Parameters** - Auto-detects {id} patterns in routes
-  **Body Examples** - Smart defaults from field names and rules

**Generate API documentation in 1 second, not 1 hour!**

##  What's New in v0.8.0

### 🌟 Advanced Debugging System

**Complete trace ID system for production debugging:**

✅ **Trace ID per request** - Every response includes `X-Siro-Trace-Id` header  
✅ **Request/Response capture** - Full context with sanitized bodies  
✅ **Request replay** - `php siro log:replay <id>` generates curl command  
✅ **Export traces** - `php siro log:export --format=json|csv`  
✅ **Smart filtering** - Filter by status, method, slow requests  
✅ **SQL query logging** - All queries captured with bindings and timing  
✅ **Auto cleanup** - Log rotation (50MB) + retention (30 days)  
✅ **Credential sanitization** - Passwords/tokens auto [REDACTED]  

**Debug production issues in 30 seconds, not 30 minutes!**

### Previous Versions

**v0.7.10** - Performance optimization (config caching, slow query logging, env validation)  
**v0.7.9** - Auth & security hardening (rate limiting, CSRF protection, complete auth system)  
**v0.7.8** - Enhanced QueryBuilder, model shortcuts, database seeders  
**v0.7.7** - Comprehensive testing infrastructure (142 unit tests)  
**v0.7.6** - Model relationships, soft deletes, resource auto-mapping  
**v0.7.5** - Smart validation, typed input helpers, file uploads  

---

**Version:** 0.8.0  
**Package:** sirosoft/core  
**Type:** library  
**Released:** April 29, 2026
