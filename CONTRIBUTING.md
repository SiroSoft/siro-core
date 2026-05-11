# Contributing to SiroPHP

Thank you for your interest in contributing to SiroPHP! This guide will help you get started.

---

## 🚀 Quick Start

### 1. Fork and Clone

```bash
# Fork the repository on GitHub, then:
git clone https://github.com/YOUR_USERNAME/siro-core.git
cd siro-core
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Run Tests

```bash
# Run all tests
php vendor/bin/phpunit

# Run specific test suite
php vendor/bin/phpunit --testsuite=Unit
php vendor/bin/phpunit --testsuite=Integration

# Run with coverage
php vendor/bin/phpunit --coverage-html coverage
```

### 4. Check Code Quality

```bash
# Static analysis
vendor/bin/phpstan analyse

# Should show: [OK] No errors
```

---

## 📋 Development Workflow

### Branch Naming

Use descriptive branch names:
```bash
# Features
git checkout -b feature/add-redis-cache

# Bug fixes
git checkout -b fix/jwt-token-expiration

# Documentation
git checkout -b docs/update-security-guide
```

### Commit Messages

Follow conventional commits format:
```bash
# Format: type(scope): description

feat(auth): add RS256 JWT support
fix(router): handle OPTIONS requests correctly
docs(readme): update installation instructions
test(validation): add edge case tests for email validation
refactor(model): simplify relationship loading
perf(cache): optimize Redis connection pooling
```

**Types:**
- `feat` - New feature
- `fix` - Bug fix
- `docs` - Documentation changes
- `style` - Code style (formatting, semicolons, etc.)
- `refactor` - Code refactoring
- `test` - Adding/updating tests
- `perf` - Performance improvements
- `chore` - Maintenance tasks

### Pull Request Process

1. **Create PR from your fork**
2. **Fill out PR template** with:
   - Description of changes
   - Related issue number (if applicable)
   - Testing performed
   - Screenshots (for UI changes)
3. **Ensure CI passes** (tests + PHPStan)
4. **Address review comments**
5. **Squash commits** if requested
6. **Maintainer merges** PR

---

## 🧪 Testing Guidelines

### Test Coverage Requirements

- **New features**: Must include tests
- **Bug fixes**: Must include regression test
- **Minimum coverage**: 80% for new code
- **All tests must pass**: Before submitting PR

### Writing Tests

#### Unit Tests

```php
<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Str;

final class StrTest extends TestCase
{
    public function testSlug(): void
    {
        $this->assertEquals('hello-world', Str::slug('Hello World'));
        $this->assertEquals('cafe-au-lait', Str::slug('Café au Lait'));
    }

    public function testRandom(): void
    {
        $random = Str::random(16);
        $this->assertEquals(16, strlen($random));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $random);
    }
}
```

#### Integration Tests

```php
<?php
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Siro\Core\App;
use Siro\Core\Route;
use Siro\Core\Response;

final class RouterTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new App();
    }

    public function testGetRoute(): void
    {
        Route::get('/test', function() {
            return Response::json(['message' => 'ok']);
        });

        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        $this->app->run();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertEquals('ok', $data['message']);
    }
}
```

#### Feature Tests

```php
<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class AuthApiTest extends TestCase
{
    public function testUserCanRegister(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => ['token', 'user']
        ]);
    }
}
```

### Running Tests

```bash
# All tests
php vendor/bin/phpunit

# Specific file
php vendor/bin/phpunit tests/Unit/StrTest.php

# Specific method
php vendor/bin/phpunit --filter=testSlug

# Test suite
php vendor/bin/phpunit --testsuite=Unit

# With verbose output
php vendor/bin/phpunit -v

# Stop on first failure
php vendor/bin/phpunit --stop-on-failure
```

---

## 📝 Coding Standards

### PHP Version

- **Minimum**: PHP 8.2
- **Target**: Latest stable PHP version
- **Features**: Use modern PHP features (typed properties, match expressions, etc.)

### Type Declarations

**Always use strict types:**
```php
<?php
declare(strict_types=1);
```

**Type all parameters and returns:**
```php
// ✅ Good
public function find(int $id): ?User
{
    return User::find($id);
}

// ❌ Bad
public function find($id)
{
    return User::find($id);
}
```

### Final Classes

**Mark core classes as final:**
```php
final class Router
{
    // Implementation
}
```

**Exception**: Abstract base classes and interfaces

### Naming Conventions

**Classes**: PascalCase
```php
class UserController { }
class UserRepository { }
```

**Methods**: camelCase
```php
public function getUserById(int $id): User { }
public function isValidEmail(string $email): bool { }
```

**Variables**: camelCase
```php
$userName = 'John';
$isActive = true;
```

**Constants**: UPPER_SNAKE_CASE
```php
const MAX_RETRIES = 3;
const DEFAULT_TIMEOUT = 30;
```

### Code Organization

**Method order in classes:**
1. Constants
2. Properties
3. Constructor
4. Public methods
5. Protected methods
6. Private methods

```php
final class UserService
{
    private const MAX_USERS = 1000;
    
    private UserRepository $repository;
    
    public function __construct(UserRepository $repository)
    {
        $this->repository = $repository;
    }
    
    public function create(array $data): User
    {
        // Implementation
    }
    
    public function findById(int $id): ?User
    {
        // Implementation
    }
    
    private function validate(array $data): void
    {
        // Implementation
    }
}
```

---

## 🔍 Code Review Checklist

Before submitting PR, ensure:

### Functionality
- [ ] Feature works as described
- [ ] Edge cases handled
- [ ] Error handling implemented
- [ ] No breaking changes (or documented)

### Code Quality
- [ ] Follows coding standards
- [ ] Type declarations present
- [ ] No code duplication
- [ ] Clear variable/method names
- [ ] Comments for complex logic

### Testing
- [ ] Tests added for new code
- [ ] All tests passing
- [ ] Test coverage > 80%
- [ ] Edge cases tested

### Documentation
- [ ] README updated (if needed)
- [ ] PHPDoc comments added
- [ ] CHANGELOG.md updated
- [ ] Breaking changes documented

### Security
- [ ] No hardcoded secrets
- [ ] Input validated/sanitized
- [ ] SQL injection prevented
- [ ] XSS protection in place

### Performance
- [ ] No N+1 queries
- [ ] Database indexes considered
- [ ] Caching used appropriately
- [ ] Memory usage reasonable

---

## 📖 Documentation Guidelines

### PHPDoc Comments

```php
/**
 * Find user by email address.
 *
 * @param string $email Email address to search
 * @return User|null User instance or null if not found
 * @throws \InvalidArgumentException If email format is invalid
 */
public function findByEmail(string $email): ?User
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \InvalidArgumentException('Invalid email format');
    }
    
    return User::where('email', $email)->first();
}
```

### README Updates

When adding new features:
1. Add to "Key Features" section
2. Include code example
3. Update table of contents
4. Add to changelog

### CHANGELOG Format

```markdown
## [0.22.0] - 2026-05-11

### Added
- RS256 JWT signature support
- Eager loading with Model::with()
- Extended validation rules (nullable, date, url)

### Fixed
- File download security vulnerability
- JWT JTI consistency issue
- Mass assignment protection bypass

### Changed
- Updated minimum PHP version to 8.2
- Improved error messages for validation

### Deprecated
- Legacy authentication method (removed in v1.0)

### Removed
- Support for PHP 7.x
```

---

## 🐛 Reporting Bugs

### Bug Report Template

**Title**: Brief description of bug

**Description**:
- What happened?
- What did you expect to happen?
- Steps to reproduce

**Environment**:
- PHP version: `php -v`
- SiroPHP version: Check composer.json
- OS: Windows/Linux/macOS
- Database: MySQL/PostgreSQL/SQLite

**Code Example**:
```php
// Minimal code that reproduces the bug
```

**Error Messages**:
```
Full error message and stack trace
```

**Additional Context**:
- Screenshots
- Log files
- Related issues

---

## 💡 Feature Requests

### Feature Request Template

**Title**: Clear feature name

**Problem**:
What problem does this solve?

**Proposed Solution**:
How should it work? Include code examples.

**Alternatives Considered**:
Other approaches you've thought about.

**Additional Context**:
Links, screenshots, mockups, etc.

---

## 🎯 Areas Needing Contribution

### High Priority
- [ ] PostgreSQL performance optimization
- [ ] GraphQL support
- [ ] WebSocket integration
- [ ] Advanced caching strategies
- [ ] More database drivers (MongoDB, etc.)

### Medium Priority
- [ ] API versioning improvements
- [ ] Rate limiting with Redis
- [ ] Enhanced validation rules
- [ ] Better error localization
- [ ] OpenAPI 3.1 support

### Low Priority
- [ ] Additional CLI commands
- [ ] More test coverage
- [ ] Documentation translations
- [ ] Example applications
- [ ] Video tutorials

---

## 🤝 Community Guidelines

### Be Respectful
- Treat everyone with respect
- Welcome newcomers
- Provide constructive feedback
- No harassment or discrimination

### Be Helpful
- Answer questions when you can
- Share knowledge freely
- Mentor junior developers
- Celebrate others' successes

### Be Professional
- Keep discussions focused
- Stay on topic
- Use appropriate language
- Follow project conventions

---

## 📞 Getting Help

### Communication Channels
- **GitHub Issues**: Bug reports and feature requests
- **GitHub Discussions**: Questions and general discussion
- **Discord**: Real-time chat (link in README)
- **Email**: maintainers@sirosoft.com

### Response Times
- Bug reports: Within 48 hours
- Feature requests: Within 1 week
- General questions: Within 3 days
- PR reviews: Within 1 week

---

## 🏆 Recognition

Contributors are recognized in:
- README.md contributors section
- Release notes
- Annual contributor highlights
- Special badges for top contributors

**Top Contributors Hall of Fame:**
- Most PRs merged
- Best documentation contributions
- Most helpful community member
- Bug hunter of the month

---

## 📜 License

By contributing to SiroPHP, you agree that your contributions will be licensed under the MIT License.

---

## ❓ FAQ

**Q: Do I need to sign a CLA?**  
A: No, just follow the contribution guidelines.

**Q: Can I contribute without coding?**  
A: Yes! Documentation, testing, and community help are valuable.

**Q: How do I become a maintainer?**  
A: Consistent quality contributions over time lead to maintainer status.

**Q: What if my PR is rejected?**  
A: Don't take it personally. Maintain rejection reasons and try again.

**Q: Can I advertise my company in PRs?**  
A: No, keep contributions focused on the project.

---

Thank you for contributing to SiroPHP! 🎉
