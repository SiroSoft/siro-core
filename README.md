# Siro Core Framework v0.8.9

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

# Storage & Scheduling (v0.8.3)
php siro storage:link                 # Create symlink for uploaded files
php siro schedule:run                 # Run scheduled tasks (for crontab)

# Queue & Mail (v0.8.4)
php siro queue:work                   # Process queued jobs
php siro queue:work --daemon          # Run worker continuously
php siro queue:status                 # Show queue status
php siro queue:retry <id>             # Retry failed job
php siro queue:flush                  # Clear failed jobs

# Multi-language (v0.8.5)
php siro make:lang vi                 # Create new language pack

# Event System (v0.8.6)
php siro make:event UserCreated       # Generate event class

# Storage, Validation & Gzip (v0.8.7)
# Storage: put(), get(), delete(), exists(), url()
# Custom validation rules via Validator::extend()
# Auto gzip compression in responses

# API Testing CLI (v0.8.8) ⭐
php siro api:test GET /users          # Quick API testing
php siro api:test POST /login --as=admin  # Auto-auth

# CRUD Scaffolding & Response Headers (v0.8.9) 🚀
php siro make:crud products           # Generate full CRUD in 30 seconds
php siro make:test ProductApi         # Integration test generator
php siro make:test ProductService --unit  # Unit test generator
# Every response includes: X-Request-Id, X-Response-Time headers
```

---

## 📦 Storage Link & Task Scheduler (v0.8.3)

### Storage Symbolic Link

Create symbolic links to serve uploaded files from the `storage/` directory via web server.

```bash
php siro storage:link
```

**Features:**
- Creates symlink: `public/storage` → `storage/public`
- Automatic fallback to Windows junction if symlink fails
- Enables serving uploaded files at `/storage/...` URLs
- Cross-platform support (Linux, macOS, Windows)

**Usage Example:**
```php
// Upload a file
$file = $request->file('avatar');
$path = $file->store('avatars'); // Saves to storage/public/avatars/xxx.jpg

// Access via URL
// http://yoursite.com/storage/avatars/xxx.jpg
```

---

### Task Scheduler with Cron Support

Run scheduled tasks automatically using Laravel-like scheduling syntax.

```bash
php siro schedule:run
```

**Setup Crontab:**
```bash
# Run every minute
* * * * * cd /path/to/project && php siro schedule:run
```

**Define Scheduled Tasks** in `routes/schedule.php`:

```php
<?php

// Run CLI command daily at midnight
$schedule->command('db:seed UserSeeder')->daily();

// Run closure every hour
$schedule->call(function () {
    // Clean old logs
    foreach (glob('storage/logs/traces/*.json') ?: [] as $file) {
        if (filemtime($file) < time() - 86400 * 7) {
            @unlink($file);
        }
    }
})->hourly();

// Custom cron expression (Monday 6:00 AM)
$schedule->command('report:weekly')->cron('0 6 * * 1');

// Call class method
$schedule->call([\App\Crons\HealthCheck::class, 'run'])->hourly();
```

**Available Scheduling Methods:**
- `->everyMinute()` - Every minute
- `->hourly()` - Every hour (minute 0)
- `->daily()` - Midnight daily
- `->dailyAt('06:30')` - Specific time daily
- `->weekly()` - Sunday midnight
- `->monthly()` - First day of month
- `->cron('0 6 * * 1')` - Custom cron expression

**Example Cron Job Class:**

```php
<?php
// app/Crons/HealthCheck.php
namespace App\Crons;

final class HealthCheck
{
    public static function run(): void
    {
        $dbOk = true;
        try {
            \Siro\Core\Database::connection()->query('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        \Siro\Core\Logger::request(
            'CRON',
            '/health-check',
            $dbOk ? 200 : 500,
            0,
            'system',
            'cron-health'
        );
    }
}
```

---

## 📧 Queue & Mail System (v0.8.4)

### Job Queue

DB-based job queue with automatic retry, priority support, and failed job tracking.

**Push a Job:**
```php
use Siro\Core\Queue;

// Simple job
Queue::push(SendEmailJob::class, ['to' => 'user@example.com']);

// With delay (3600 seconds = 1 hour)
Queue::push(ProcessReportJob::class, $data, delay: 3600);

// With priority (higher runs first)
Queue::push(UrgentJob::class, $data, priority: 10);

// Custom retry attempts and timeout
Queue::push(HeavyJob::class, $data, maxAttempts: 5, timeout: 300);
```

**Create a Job Class:**
```php
<?php
namespace App\Jobs;

final class SendEmailJob
{
    public function handle(array $data = []): void
    {
        // Your logic here
        mail($data['to'], 'Subject', 'Body');
    }
}
```

**Run Queue Worker:**
```bash
# Process all available jobs and exit
php siro queue:work

# Run continuously (daemon mode)
php siro queue:work --daemon

# Custom sleep between polls
php siro queue:work --daemon --sleep=3

# Override max attempts
php siro queue:work --tries=5
```

**Setup Crontab for Production:**
```bash
# Add to crontab
* * * * * cd /path/to/project && php siro queue:work

# Or use supervisor for daemon mode
```

**Queue Management:**
```bash
# Check queue status
php siro queue:status

# Retry a specific failed job
php siro queue:retry 123

# Retry all failed jobs
php siro queue:retry all

# Clear all failed jobs
php siro queue:flush
```

**Features:**
- ✅ Automatic retry with exponential backoff (5s, 10s, 20s... max 300s)
- ✅ Priority support (higher priority jobs run first)
- ✅ Job timeout protection (default 120s)
- ✅ Failed jobs tracking in `failed_jobs` table
- ✅ Lock mechanism prevents duplicate processing
- ✅ Works with SQLite, MySQL, PostgreSQL

---

### Email System

Send emails via sendmail or SMTP with no external dependencies.

**Send Immediately:**
```php
use Siro\Core\Mail;

Mail::to('user@example.com')
    ->subject('Welcome!')
    ->html('<h1>Hello!</h1>')
    ->send();
```

**Queue for Async Delivery:**
```php
Mail::to('user@example.com')
    ->subject('Welcome!')
    ->html('<h1>Hello!</h1>')
    ->queue();  // Pushes to queue
```

**Delayed Delivery:**
```php
Mail::to('user@example.com')
    ->subject('Welcome!')
    ->html('<h1>Hello!</h1>')
    ->sendLater(3600);  // Send after 1 hour
```

**Advanced Options:**
```php
Mail::to('user@example.com')
    ->subject('Report')
    ->html('<h1>Monthly Report</h1>')
    ->cc('manager@example.com')
    ->bcc('archive@example.com')
    ->replyTo('support@example.com')
    ->attach('/path/to/report.pdf')
    ->send();
```

**Configuration (.env):**
```env
# Driver: sendmail or smtp
MAIL_DRIVER=smtp

# SMTP settings
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password

# From address
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Siro API"
```

**Create Email Templates:**
```php
<?php
namespace App\Mails;

final class WelcomeMail
{
    public function build(array $data = []): string
    {
        $name = $data['name'] ?? 'User';
        
        return <<<HTML
<!DOCTYPE html>
<html>
<body>
    <h1>Welcome, {$name}!</h1>
    <p>Thank you for joining us.</p>
</body>
</html>
HTML;
    }
}
```

**Usage:**
```php
Mail::to('user@example.com')
    ->subject('Welcome!')
    ->html((new WelcomeMail())->build(['name' => 'John']))
    ->send();
```

**Features:**
- ✅ Sendmail driver (PHP mail())
- ✅ SMTP driver with STARTTLS and AUTH LOGIN
- ✅ No external dependencies (uses fsockopen)
- ✅ HTML and plain text support
- ✅ CC, BCC, Reply-To
- ✅ File attachments with MIME encoding
- ✅ Queue integration for async delivery
- ✅ Delayed delivery support

---

## 🌍 Multi-language Support (v0.8.5)

Built-in internationalization (i18n) system with locale detection, fallback, and parameter replacement.

**Configuration (.env):**
```env
APP_LOCALE=en              # Default locale
APP_FALLBACK_LOCALE=en     # Fallback when key is missing
```

**Get Translations:**
```php
use Siro\Core\Lang;

// Simple translation
$message = Lang::get('messages.welcome');  // "Welcome"

// With parameters
$error = Lang::get('validation.required', ['field' => 'Email']);
// Output: "Email is required"

// Check if key exists
if (Lang::has('messages.goodbye')) {
    echo Lang::get('messages.goodbye');
}

// Pluralization
$apples = Lang::plural('messages.apples', 5);
// Output: "5 apples"
$apples = Lang::plural('messages.apples', 1);
// Output: "1 apple"
```

**Create Language Files:**

Directory structure:
```
storage/lang/
├── en/
│   ├── messages.php
│   └── validation.php
├── vi/
│   ├── messages.php
│   └── validation.php
└── fr/
    ├── messages.php
    └── validation.php
```

Example `storage/lang/en/messages.php`:
```php
<?php
return [
    'welcome'      => 'Welcome',
    'goodbye'      => 'Goodbye',
    'not_found'    => 'Not found',
    'server_error' => 'Internal server error',
    'success'      => 'Success',
    'created'      => 'Created successfully',
];
```

Example `storage/lang/vi/messages.php`:
```php
<?php
return [
    'welcome'      => 'Chào mừng',
    'goodbye'      => 'Tạm biệt',
    'not_found'    => 'Không tìm thấy',
    'server_error' => 'Lỗi máy chủ',
    'success'      => 'Thành công',
    'created'      => 'Tạo thành công',
];
```

**Auto Locale Detection:**

The framework automatically detects user's language from HTTP headers:

Priority order:
1. `X-Locale` header (for testing/API clients)
2. `Accept-Language` header (browser default)
3. `APP_LOCALE` environment variable (fallback)

**Usage in Routes:**
```php
$router->get('/', function (): array {
    return [
        'message' => Lang::get('messages.welcome'),
        'locale' => Lang::locale(),  // Current locale
    ];
});
```

**Test Different Locales:**
```bash
# Default (English)
curl http://localhost:8000/
# {"message":"Welcome","locale":"en"}

# Vietnamese
curl -H "Accept-Language: vi" http://localhost:8000/
# {"message":"Chào mừng","locale":"vi"}

# Using X-Locale header
curl -H "X-Locale: fr" http://localhost:8000/
# {"message":"Bienvenue","locale":"fr"}
```

**Generate Language Pack:**
```bash
php siro make:lang vi    # Creates storage/lang/vi/
php siro make:lang fr    # Creates storage/lang/fr/
```

**Features:**
- ✅ Dot-notation keys (`messages.welcome.nested`)
- ✅ Parameter replacement (`:field`, `:count`)
- ✅ Locale fallback mechanism
- ✅ Auto-detection from Accept-Language header
- ✅ File caching for performance
- ✅ Pluralization support
- ✅ Easy to add new languages
- ✅ Validator auto-translates errors

---

## ⚡ Event System (v0.8.6)

Lightweight publish/subscribe event dispatcher with wildcard support, one-time listeners, and Model lifecycle hooks.

**Basic Usage:**
```php
use Siro\Core\Event;

// Register listener
Event::on('users.created', function ($user) {
    Log::info('New user: ' . $user->email);
});

// Fire event
Event::emit('users.created', $user);
```

**One-time Listener:**
```php
Event::once('users.created', function ($user) {
    // Runs exactly once, then auto-removes
});
```

**Wildcard Listeners:**
```php
Event::on('users.*', function ($payload) {
    // Catches: users.created, users.updated, users.deleted, etc.
});
```

**Cancel Operations:**
```php
Event::on('users.creating', function ($user): bool {
    if ($user->email === 'banned@example.com') {
        return false; // Cancel creation
    }
    return true;
});
```

**Remove Listeners:**
```php
// Remove specific event
Event::off('users.created');

// Remove with wildcard
Event::off('users.*');

// Remove all
Event::flush();
```

**Check Listeners:**
```php
if (Event::hasListeners('users.created')) {
    // Has listeners
}
```

---

### Model Lifecycle Events

Models automatically fire events during CRUD operations:

**Create Flow:**
```
saving → creating → INSERT → created → saved
```

**Update Flow:**
```
saving → updating → UPDATE → updated → saved
```

**Delete Flow:**
```
deleting → DELETE → deleted
```

**Usage Example:**
```php
use App\Models\User;

// Listen to user creation
Event::on('users.creating', function ($user): bool {
    // Validate before create
    if (User::where('email', $user->email)->exists()) {
        return false; // Cancel
    }
    return true;
});

Event::on('users.created', function ($user) {
    // Send welcome email
    Mail::to($user->email)
        ->subject('Welcome!')
        ->html('<h1>Welcome!</h1>')
        ->queue();
});

// Create user (events fire automatically)
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
```

---

### Event Classes

Generate structured event classes:

```bash
php siro make:event UserCreated
# Creates: app/Events/UserCreatedEvent.php
```

**Generated Class:**
```php
<?php
namespace App\Events;

use Siro\Core\Event;

final class UserCreatedEvent
{
    public static function dispatch(mixed $payload = null): void
    {
        Event::emit('user_created_event', $payload);
    }

    public static function listen(callable $callback): void
    {
        Event::on('user_created_event', $callback);
    }
}
```

**Usage:**
```php
// Listen
UserCreatedEvent::listen(function ($user) {
    Log::info('User created: ' . $user->email);
});

// Dispatch
UserCreatedEvent::dispatch($user);
```

---

### Real-world Examples

**1. Audit Logging:**
```php
Event::on('users.*', function ($user) {
    AuditLog::create([
        'action' => Event::currentEvent(),
        'user_id' => $user->id,
        'timestamp' => now(),
    ]);
});
```

**2. Cache Invalidation:**
```php
Event::on('products.updated', function ($product) {
    Cache::forget('product.' . $product->id);
});

Event::on('products.deleted', function ($product) {
    Cache::forget('product.' . $product->id);
});
```

**3. Notification System:**
```php
Event::on('orders.completed', function ($order) {
    // Send email
    Mail::to($order->user->email)
        ->subject('Order Completed')
        ->html(OrderCompletedMail::build($order))
        ->queue();
    
    // Send SMS
    SmsService::send($order->user->phone, 'Your order is completed!');
});
```

**4. Validation Before Save:**
```php
Event::on('users.saving', function ($user): bool {
    if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
        throw new ValidationException('Invalid email');
    }
    return true;
});
```

**5. Queue Heavy Operations:**
```php
Event::on('reports.generated', function ($report) {
    // Offload to queue
    Queue::push(SendReportEmailJob::class, [
        'report_id' => $report->id,
        'user_id' => $report->user_id
    ]);
});
```

---

### Features

- ✅ Simple pub/sub pattern
- ✅ Wildcard event matching (`users.*`)
- ✅ One-time listeners (`once()`)
- ✅ Event cancellation (return false)
- ✅ Multiple listeners per event
- ✅ Payload passing
- ✅ Listener removal (`off()`)
- ✅ Check for listeners (`hasListeners()`)
- ✅ Clear all listeners (`flush()`)
- ✅ Model lifecycle integration
- ✅ Zero external dependencies
- ✅ Fast and lightweight

---

## 📁 File Storage (v0.8.7)

Unified file storage abstraction supporting local filesystem and S3-compatible object storage.

**Configuration (.env):**
```env
# Local driver (default)
STORAGE_DRIVER=local
STORAGE_PATH=storage/app

# S3 driver
# STORAGE_DRIVER=s3
# STORAGE_S3_KEY=your-key
# STORAGE_S3_SECRET=your-secret
# STORAGE_S3_REGION=us-east-1
# STORAGE_S3_BUCKET=my-bucket
# STORAGE_S3_ENDPOINT=  # Optional for S3-compatible services
```

**Basic Usage:**
```php
use Siro\Core\Storage;

// Write file
Storage::put('documents/report.pdf', $pdfContent);

// Read file
$content = Storage::get('documents/report.pdf');

// Check existence
if (Storage::exists('documents/report.pdf')) {
    // File exists
}

// Delete file
Storage::delete('documents/report.pdf');

// Get URL
$url = Storage::url('documents/report.pdf');
// Local: /storage/documents/report.pdf
// S3: https://bucket.s3.region.amazonaws.com/documents/report.pdf

// List files (local only)
$files = Storage::files('documents');
// ['report.pdf', 'invoice.pdf']
```

**Upload Example:**
```php
$file = $request->file('avatar');
$path = 'avatars/' . uniqid() . '.' . $file->extension();

Storage::put($path, file_get_contents($file->tmpName));

return [
    'url' => Storage::url($path),
];
```

**Features:**
- ✅ Local filesystem driver (default)
- ✅ S3/S3-compatible driver (MinIO, DigitalOcean Spaces, etc.)
- ✅ Same API for both drivers
- ✅ No external dependencies for S3 (uses HTTP directly)
- ✅ Automatic directory creation
- ✅ MIME type detection
- ✅ AWS Signature V4 authentication

---

## ✅ Custom Validation Rules (v0.8.7)

Extend the Validator with custom rules using `Validator::extend()`.

**Register Custom Rule:**
```php
use Siro\Core\Validator;

Validator::extend('phone', function ($value, $field, $input, $param): string|bool {
    return preg_match('/^\+?[0-9]{7,15}$/', (string) $value)
        ? true
        : ':field is not a valid phone number';
});
```

**Use in Validation:**
```php
$request->validate([
    'phone' => 'required|phone',
]);
```

**With Parameters:**
```php
Validator::extend('min_words', function ($value, $field, $input, $param): string|bool {
    $minWords = (int) ($param ?? 1);
    $wordCount = str_word_count((string) $value);
    
    return $wordCount >= $minWords
        ? true
        : ":field must have at least {$minWords} words";
});

// Usage
$request->validate([
    'description' => 'min_words:10',
]);
```

**Complex Validation:**
```php
Validator::extend('unique_email_domain', function ($value, $field, $input, $param): string|bool {
    $domain = substr(strrchr($value, '@'), 1);
    $blockedDomains = ['spam.com', 'fake.org'];
    
    if (in_array($domain, $blockedDomains)) {
        return ":field domain is not allowed";
    }
    
    return true;
});
```

**Return Types:**
- `true` - Validation passed
- `false` - Validation failed (uses default error message)
- `string` - Validation failed with custom error message

**Features:**
- ✅ Simple registration API
- ✅ Access to full input data
- ✅ Support for rule parameters
- ✅ Custom error messages
- ✅ Works with multi-language system
- ✅ Can combine with built-in rules

---

## 🗜️ Auto Gzip Compression (v0.8.7)

Automatic response compression when client supports it. Zero configuration required!

**How It Works:**
```php
// In Response::send()
$acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

if (str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
    header('Content-Encoding: gzip');
    echo gzencode($encoded);
    return;
}

echo $encoded;  // Uncompressed for clients without gzip support
```

**Benefits:**
- ✅ Reduces bandwidth by 60-80%
- ✅ Faster page loads
- ✅ Zero configuration
- ✅ Backward compatible (clients without gzip get uncompressed)
- ✅ Uses PHP's built-in `gzencode()`
- ✅ No external dependencies

**Example:**
```json
// Without gzip: 10KB
{
  "data": [...],
  "message": "Success"
}

// With gzip: ~2KB (80% reduction)
[gzipped binary data]
```

**Browser Support:**
All modern browsers send `Accept-Encoding: gzip` header automatically:
- Chrome ✅
- Firefox ✅
- Safari ✅
- Edge ✅
- Mobile browsers ✅

---

## 🚀 CRUD Scaffolding & Testing (v0.8.9)

### `php siro make:crud` - Full CRUD in 30 Seconds

Generate complete CRUD with a single command:

```bash
php siro make:crud products
```

**Generates 6 files automatically:**
- ✅ `app/Models/Product.php` - Model with fillable fields
- ✅ `database/migrations/YYYY_create_products_table.php` - Migration
- ✅ `app/Controllers/ProductController.php` - Full CRUD controller
- ✅ `app/Resources/ProductResource.php` - Resource transformer
- ✅ `routes/api.php` - Auto-injected routes
- ✅ `tests/products_test.php` - Integration tests (4 cases)

**Smart Features:**
- Intelligent pluralization (category → categories)
- Prevents overwrites (asks for confirmation)
- Auto-detects existing routes
- Includes validation rules
- Pagination support built-in

### `php siro make:test` - Test Generator

```bash
# API integration test (default)
php siro make:test UserApi

# Unit test
php siro make:test UserService --unit
```

**API Test Template Includes:**
- App bootstrapping
- `dispatch()` helper for internal requests
- `test()` function with colored output
- ValidationException handling
- Pre-configured structure

### Response Headers

Every response now includes:

```
X-Request-Id: a1b2c3d4e5f67890      # Unique trace ID
X-Response-Time: 8.45ms              # Processing time
```

**Benefits:**
- 🔍 Debug specific requests across logs
- 📊 Monitor API performance
- 🛠️ Correlate errors with request IDs
- 📈 Track response times in production

---

## ⭐ CLI API Testing Tool (v0.8.8)

Built-in API testing command that replaces Postman for quick endpoint testing.

**Basic Usage:**
```bash
# Test GET endpoint
php siro api:test GET /api/users

# Test POST with data
php siro api:test POST /auth/login email=admin@test.com password=123456

# Auto-authentication (login once, token saved)
php siro api:test POST /auth/login email=admin@test.com password=123 --as=admin
php siro api:test GET /users --as=admin              # Auto uses token
php siro api:test POST /users name=John --as=admin   # Auto uses token

# View request history
php siro api:test --history
php siro api:test --history=20

# Custom headers & port
php siro api:test GET /api/data --header="X-Version: 2.0" --port=8080
```

**Features:**
- ✅ **Zero dependencies** - Uses PHP built-in cURL
- ✅ **Auto authentication** - Login once, token saved by role
- ✅ **Pretty output** - Colored status codes, formatted JSON
- ✅ **Request history** - Saves last 100 requests
- ✅ **Multiple content types** - JSON (default) or form-urlencoded
- ✅ **Custom headers** - Add any headers you need
- ✅ **Security-first** - No shell commands, tokens stored securely

**Example Output:**
```
  POST /auth/login
  Status: 200 OK
  Time:   45.2ms
  Size:   1.2KB

  Response Headers:
    HTTP/1.1 200 OK
    Content-Type: application/json

  Body:
  {
      "success": true,
      "data": {
          "token": "eyJ0eXAi..."
      }
  }

  ✓ Token for 'admin' saved.
```

**Why Use api:test?**
- 🚀 **Faster than Postman** - No GUI overhead, instant startup
- 🔐 **Smart auth** - Automatic token management by role
- 📊 **History tracking** - Review your testing session
- 💻 **CLI-native** - Stay in terminal, keep context
- 🎯 **Project-specific** - Perfect integration with SiroPHP

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
