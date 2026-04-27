# Siro Core Framework

**Siro API Framework Core** - Lightweight PHP Micro-Framework Core Library

## Description

This is the core library for Siro API Framework. It provides essential components for building REST APIs:

- ⚡ **Router & Request/Response** - Fast routing with middleware support
- 🗄️ **Database QueryBuilder** - PDO-based query builder with automatic caching
- 🔐 **JWT Authentication** - Built-in JWT token generation and verification
- 💾 **Cache System** - File and Redis cache drivers
- 🛠️ **Console Commands** - CLI tools for migrations, scaffolding, and more
- ✅ **Validation** - Request validation utilities
- 📦 **Resource Transformation** - API response formatting

## Installation

```bash
composer require siro/core
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
        'version' => '0.7.4'
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

## Credits

Created and maintained by SiroSoft Team

---

**Version:** 0.7.4  
**Package:** siro/core  
**Type:** library
