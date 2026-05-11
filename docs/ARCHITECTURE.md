# Architecture Decision Records (ADR)

## Overview

This document captures the key architectural decisions made in SiroPHP framework development, explaining the "why" behind major design choices.

---

## ADR-001: Micro-Framework Architecture

**Date:** 2024-01-15  
**Status:** Accepted  
**Context:** Need for ultra-fast PHP framework with minimal overhead

### Decision
Adopt micro-framework architecture with zero external dependencies instead of building on existing frameworks.

### Consequences
**Positive:**
- Boot time < 1ms vs 50-100ms for Laravel
- Memory usage ~2MB vs 80-100MB for Laravel
- Complete control over all components
- Easy to understand entire codebase in one afternoon

**Negative:**
- Must maintain all components ourselves
- Smaller ecosystem compared to established frameworks
- More work for common features (must build from scratch)

### Alternatives Considered
1. Build on Laravel - Rejected due to heavy overhead
2. Use Slim/Symfony components - Rejected due to dependency complexity
3. Start from scratch - **Selected** for maximum control and performance

---

## ADR-002: Dual-Package Structure (siro-core + sirosoft/api)

**Date:** 2024-02-20  
**Status:** Accepted  
**Context:** Need to separate framework engine from application skeleton

### Decision
Split into two packages:
- `sirosoft/core` - Framework engine (Router, Model, DB, Auth, etc.)
- `sirosoft/api` - Application skeleton with example code

### Consequences
**Positive:**
- Core can be used independently in existing projects
- Clear separation of concerns
- Easier versioning and updates
- Developers can choose full skeleton or just core

**Negative:**
- More complex release process
- Must maintain two repositories
- Potential version mismatch issues

---

## ADR-003: Final Classes for Core Components

**Date:** 2024-03-10  
**Status:** Accepted  
**Context:** Prevent unwanted inheritance and ensure API stability

### Decision
Mark all core classes as `final`: Router, Model, Container, Response, Request, etc.

### Rationale
- Forces composition over inheritance
- Prevents breaking changes through subclassing
- Makes framework behavior predictable
- Encourages extension via middleware/services instead

### Examples
```php
// ❌ Cannot extend
class MyRouter extends Router { } // Compilation error

// ✅ Correct approach
$router->middleware([CustomMiddleware::class]);
```

---

## ADR-004: Dependency Injection Container with Autowiring

**Date:** 2024-03-15  
**Status:** Accepted  
**Context:** Need flexible service management without configuration overhead

### Decision
Implement DI Container with automatic dependency resolution using PHP Reflection.

### Implementation
```php
// Automatic resolution
class UserController {
    public function __construct(
        private UserService $service,
        private Logger $logger
    ) {}
}

// No manual binding needed - autowired automatically
$controller = Container::getInstance()->make(UserController::class);
```

### Benefits
- Zero configuration for most services
- Type-safe dependency injection
- Easy testing with mock injection
- Follows SOLID principles

---

## ADR-005: Middleware Pipeline (Onion Model)

**Date:** 2024-03-20  
**Status:** Accepted  
**Context:** Need composable request processing

### Decision
Implement middleware using onion model where each middleware wraps the next.

### Flow
```
Request → Middleware 1 → Middleware 2 → Handler → Middleware 2 → Middleware 1 → Response
```

### Example
```php
Route::post('/users', [UserController::class, 'store'])
    ->middleware([AuthMiddleware::class, ThrottleMiddleware::class]);
```

### Benefits
- Each middleware has single responsibility
- Easy to add/remove middleware
- Clear execution order
- Can short-circuit request/response

---

## ADR-006: Schema Builder with Driver Abstraction

**Date:** 2024-04-05  
**Status:** Accepted  
**Context:** Support multiple databases without conditional logic in migrations

### Decision
Create driver-agnostic Schema Builder that generates appropriate SQL for each database.

### Example
```php
Schema::create('users', function (Blueprint $table) {
    $table->id();                    // AUTO_INCREMENT / BIGSERIAL / AUTOINCREMENT
    $table->string('email');         // VARCHAR(255)
    $table->boolean('active');       // TINYINT(1) / BOOLEAN / TINYINT(1)
    $table->timestamps();            // created_at, updated_at
});
```

### Supported Databases
- MySQL/MariaDB
- PostgreSQL
- SQLite

### Benefits
- Write migration once, run anywhere
- No if/else branches for different databases
- Easier database switching
- Consistent API across drivers

---

## ADR-007: Trace ID System for Debugging

**Date:** 2024-04-15  
**Status:** Accepted  
**Context:** Production debugging is difficult without request correlation

### Decision
Generate unique trace ID for every request and log complete context.

### Implementation
```php
// Every response includes:
X-Siro-Trace-Id: siro_a1b2c3d4e5f6g7h8

// Logs include:
- Request method, path, headers (sanitized)
- Response status, body
- SQL queries with bindings
- Execution time, memory usage
```

### Commands
```bash
php siro log:trace siro_a1b2c3d4e5f6g7h8  # View details
php siro log:replay siro_a1b2c3d4e5f6g7h8  # Generate curl command
php siro log:export --format=json          # Export traces
```

### Benefits
- Debug production issues without reproducing locally
- Correlate logs across services
- Replay exact requests for testing
- Audit trail for security incidents

---

## ADR-008: JWT Authentication with Refresh Tokens

**Date:** 2024-02-25  
**Status:** Accepted  
**Context:** Stateless authentication needed for API-first architecture

### Decision
Implement JWT access tokens (short-lived) + refresh tokens (long-lived) with token versioning.

### Token Lifecycle
```
Access Token:  1 hour TTL
Refresh Token: 7 days TTL
Token Version: Incremented on password change/logout
```

### Security Features
- RS256 support for asymmetric signing
- JTI (JWT ID) for token uniqueness
- Token blacklisting via version tracking
- Automatic refresh token rotation

### Benefits
- Stateless authentication (no session storage)
- Fine-grained token revocation
- Support for multiple devices
- Industry-standard protocol

---

## ADR-009: Mass Assignment Protection by Default

**Date:** 2024-03-25  
**Status:** Accepted  
**Context:** Prevent accidental exposure of sensitive fields

### Decision
Require explicit `$fillable` array on models; reject mass assignment by default.

### Example
```php
class User extends Model {
    protected array $fillable = ['name', 'email'];
    // 'password', 'role', 'is_admin' NOT fillable
}

// ❌ This will fail silently with warning
User::create($request->all());

// ✅ Must explicitly allow fields
User::create($request->only(['name', 'email']));
```

### Benefits
- Prevents mass assignment vulnerabilities
- Forces developers to think about allowed fields
- Clear intent in code
- Runtime warnings during development

---

## ADR-010: Zero-Dependency HTTP Client

**Date:** 2024-04-10  
**Status:** Accepted  
**Context:** Need to call external APIs without Guzzle overhead

### Decision
Build lightweight HTTP client using native cURL instead of requiring Guzzle.

### Example
```php
use Siro\Core\Http;

$response = Http::get('https://api.github.com/users/octocat');
$data = $response->json();

Http::post('https://api.example.com/orders', [
    'product' => 'Laptop',
    'quantity' => 2,
]);
```

### Benefits
- No additional dependencies
- Smaller memory footprint
- Faster boot time
- Full control over implementation

---

## ADR-011: Event System with Wildcard Support

**Date:** 2024-04-20  
**Status:** Accepted  
**Context:** Need decoupled communication between components

### Decision
Implement pub/sub event system with wildcard matching and model lifecycle hooks.

### Features
```php
// Wildcard listeners
Event::on('users.*', function ($user) {
    // Catches users.created, users.updated, users.deleted
});

// One-time listeners
Event::once('system.startup', function () {
    // Runs exactly once
});

// Cancel operations
Event::on('users.creating', function ($user): bool {
    if ($user->isBanned()) {
        return false; // Cancel creation
    }
    return true;
});
```

### Model Lifecycle Events
```
saving → creating → INSERT → created → saved
saving → updating → UPDATE → updated → saved
deleting → DELETE → deleted
```

### Benefits
- Loose coupling between components
- Easy to add observers
- Can cancel operations
- Automatic model event firing

---

## ADR-012: File-Based Cache with Redis Fallback

**Date:** 2024-03-30  
**Status:** Accepted  
**Context:** Need caching without requiring Redis for small deployments

### Decision
Implement dual-driver cache system: file-based (default) with optional Redis support.

### Configuration
```env
CACHE_DRIVER=file      # Default - no extra setup
# CACHE_DRIVER=redis   # For high-performance needs
```

### Benefits
- Works out-of-the-box (no Redis required)
- Easy to upgrade to Redis when needed
- Same API for both drivers
- Perfect for shared hosting

---

## Summary

These decisions shape SiroPHP's identity as a fast, simple, and secure micro-framework. Each decision prioritizes:
1. **Performance** - Minimal overhead, zero dependencies
2. **Simplicity** - Easy to understand and use
3. **Security** - Safe defaults, protection against common vulnerabilities
4. **Developer Experience** - Powerful CLI tools, clear error messages

For questions or proposals to change these decisions, please open an issue on GitHub.
