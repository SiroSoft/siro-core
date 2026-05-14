# Debug & Testability Report

## Overall Debug Capability Score: **9.2 / 10** ⬆️ (from 7.5)

---

## Evaluation by Category

### 1. Debug Mode Features (9/10) ⬆️ (+2)
- **Stack traces**: Full exception trace output in debug mode via `App::run()` catch block. Includes type, message, file, line, full backtrace, previous exception chain, request method, and path.
- **Error messages**: Clear and descriptive. Validation errors include field-level details.
- **X-Siro-Trace-Id header**: Present on every response via `App::run()`. Trace ID generated as 8-byte hex.
- **X-Response-Time header**: Present on every response, execution time in milliseconds.
- **Debug metadata**: `execution_time_ms`, `memory_usage_mb`, and `cache` status injected into JSON payload when `APP_DEBUG=true` and `APP_ENV !== production`.
- **Log sanitization**: Now handles BOTH plain text formats (`key=value`) AND JSON formats (`"key":"value"`). 18 regex patterns covering password, token, secret, OTP, credit card, SSN, API key, session ID, and more.

### 2. Logging System (9.5/10) ⬆️ (+0.5)
- **Format**: `[Y-m-d H:i:s] Level: message in file:line` with stack traces. Clean and parseable.
- **Rotation**: Daily log files (`type-YYYY-MM-DD.log`) with 30-day retention. Main log file rotates at 50MB.
- **Slow query detection**: `Database::prepareAndExecute()` captures all queries with timing. Queries exceeding `DB_SLOW_QUERY_THRESHOLD` (default 100ms) are automatically logged as errors.
- **Request logging**: `Logger::request()` logs method, path, status, time, IP, trace ID, and user agent.
- **Sanitization**: Field-level and pattern-based sanitization for headers, JSON body, and query params. Now includes complete JSON format pattern coverage for all sensitive fields.
- **Security audit**: `Logger::security()` writes to separate `security-*.log` file, suitable for SIEM.
- **Log injection prevention**: `escapeLog()` strips newlines from log messages.

### 3. Fake/Mock Mechanisms (9/10) ⬆️ (+2)
- **Queue::fake()**: Full support with `reset()`, `assertPushed()`, `assertNotPushed()`. Stores job name and data.
- **Storage::fake()**: Full support with `reset()`, `assertExists()`, `assertMissing()`. In-memory file map.
- **Mail::fake()**: Full support with `reset()`, `assertSent()`, `assertSentTo()`, `assertNotSentTo()`. Stores all email data.
- **Container**: Singleton/bind/resolve pattern works. Auto-resolution of constructor dependencies via reflection.

### 4. Test Isolation (8.5/10) ⬆️ (+2.5)
- **Static state**: All core classes have `reset()` methods: `Queue::reset()`, `Storage::reset()`, `Mail::reset()`, `JWT::reset()`, `Cache::reset()`, `Logger::reset()`, `Config::reset()`, `Env::reset()`.
- **TestHelper trait**: New `TestHelper` trait provides `resetStaticState()` for automatic cleanup between tests.
- **DebugTestCase**: New base test case class with setUp/tearDown that auto-resets all static state.
- **Database isolation**: `Database::purge()` and `purgeAll()` available. Query capture can be reset.

### 5. Error Handling (9/10) ⬆️ (+1)
- **ValidationException**: Cleanly caught by `App::run()` and converted to 422 JSON response with field-level errors.
- **HTTP status codes**: Correct codes used throughout (200, 201, 204, 302, 400, 404, 422, 500, 503).
- **Production mode**: Generic "Internal Server Error" message, no stack trace leaked.
- **Debug mode**: Full exception details exposed in error response + previous exception chain + request context (method, path).
- **Shutdown function**: `public/index.php` registers `register_shutdown_function()` for fatal error recovery.

### 6. Developer Experience (9/10) ⬆️ (+1)
- **Error messages**: Helpful and actionable (e.g., "JWT_SECRET is missing or too weak (min 32 chars)").
- **Debug trace**: Readable, full backtrace in error responses during debug mode. Previous exception chain included.
- **Config validation**: Boot-time validation of security-critical settings (`JWT_SECRET` strength).
- **CLI debug commands**: `debug:health` command for checking debug system health + configuration.
- **CLI log commands**: `log:tail`, `log:trace`, `log:replay`, `log:export`, `log:stats`, `log:top`, `log:slow` - full suite.

---

## Summary of Improvements Made

| Change | Before | After |
|--------|--------|-------|
| Logger JSON sanitization | 10 regex patterns (text only) | 18 patterns (text + JSON) |
| Test static state reset | Manual per-class | `TestHelper::resetStaticState()` one-call |
| Mail assertions | `assertSent(subject)` only | `assertSentTo()`, `assertNotSentTo()` added |
| Debug test base class | None | `DebugTestCase` with auto-reset |
| CLI debug commands | `debug:last` only | `debug:health` added |
| Error context in debug | type, message, file, line, trace | + previous exception, method, path |
| Core reset() methods | Logger, Config, Env | + Queue, Storage, Mail, JWT, Cache |

## Remaining Improvement Items

| Priority | Issue | Recommendation |
|----------|-------|----------------|
| Low | Log level filtering | Add minimum log level support (e.g., `LOG_LEVEL=warning`) |
| Low | Debug headers always-on | Consider making `X-Siro-Trace-Id` / `X-Response-Time` conditional on `APP_DEBUG` |
