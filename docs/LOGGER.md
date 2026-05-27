---
title: L OG GE R
description: SiroPHP L OG GE R reference
sidebar_position: 7
sidebar_label: L OG GE R
---

# Logger

The siro-core Logger provides structured, secure logging with automatic sanitization, daily rotation (month-partitioned), SIEM-ready security audit trails, trace logging (hash-prefix partitioned), and slow query detection.

---

## Configuration

### Boot

Call `Logger::boot()` early in your application bootstrap to initialise the log directory, set retention, and create access-denial files.

```php
use Siro\Core\Logger;

Logger::boot(__DIR__);                // uses <basePath>/storage/logs/
Logger::boot('/var/www/myapp/public'); // custom base path
```

### Log Levels & Retention

| Environment Variable        | Default | Description                                    |
|-----------------------------|---------|------------------------------------------------|
| `LOG_LEVEL`                 | `debug` | Set to `error` to suppress debug logs entirely |
| `LOG_RETENTION_DAYS`        | `30`    | Days to keep daily log files before cleanup     |
| `LOG_MAX_SIZE_MB`           | `1024`  | Total log size limit (MB); oldest files auto-deleted |
| `DB_SLOW_QUERY_THRESHOLD`   | `100`   | Query duration in ms above which it is flagged  |

These are read automatically inside `boot()`:

```php
Logger::boot(__DIR__);
// LOG_LEVEL=error          → debug() writes nothing, 0 I/O
// LOG_RETENTION_DAYS=60    → keeps logs for 60 days
// LOG_MAX_SIZE_MB=512      → max 512MB total, auto-purge oldest
// DB_SLOW_QUERY_THRESHOLD=200 → flags queries slower than 200ms
```

### Reset

```php
Logger::reset(); // clears all internal state
```

---

## Directory Structure

Logs are automatically organised into three subdirectories to prevent flat-folder performance degradation:

```
storage/logs/
  daily/2026-05/          ← month-partitioned daily files
    error-2026-05-13.log
    request-2026-05-13.log
    slow-2026-05-13.log
    security-2026-05-13.log
    debug-2026-05-13.log
  main/                   ← cumulative files (rotated at 50MB)
    error.log
    slow.log
    security.log
  traces/2026/05/13/ab/   ← date + hash-prefix partitioned
    trace-xxx.json
    trace-yyy.json
```

- **Daily logs**: partitioned by `YYYY-MM` (1 folder per month). Cleanup scans only the current month.
- **Main logs**: cumulative `error.log`, `slow.log`, `security.log` with 50MB rotation.
- **Traces**: partitioned by date (`YYYY/MM/DD`) then by 2-char hash prefix (`00`–`ff`). Each subdirectory holds at most ~256 files.

---

## Log Types

### Request Logging

Every HTTP request can be logged with method, path, status code, duration, client IP, trace ID and user agent.

```php
Logger::request('POST', '/api/users', 201, 45.32, '192.168.1.1', 'trace-abc-123', 'Mozilla/5.0 ...');
```

Output in `storage/logs/daily/2026-05/request-2026-05-13.log`:

```
2026-05-13 10:30:00 | POST | /api/users | 201 | 45.32ms | trace:trace-abc-123 | ip:192.168.1.1 | ua:Mozilla/5.0 ...
```

### Error Logging

Log exceptions or plain strings. Stack traces are automatically sanitised. Written to both the daily file and a persistent `error.log`.

```php
try {
    // ...
} catch (\Throwable $e) {
    Logger::error($e);
}
```

```php
Logger::error('Something went wrong');
```

Written to `error-YYYY-MM-DD.log` and `error.log`.

### Debug Logging

Optional debug messages with structured context. Not written to the persistent main file (daily only).

```php
Logger::debug('Processing order', ['order_id' => 1234, 'items' => 5]);
```

Output in `debug-YYYY-MM-DD.log`:

```
[2026-05-13 10:30:00] [DEBUG] Processing order {"order_id":1234,"items":5}
```

### Security Audit Log (SIEM-ready)

Every security-relevant event is captured with structured context. Always written to both the daily file and a persistent `security.log` for SIEM ingestion.

```php
Logger::security('auth.failed', ['ip' => '192.168.1.1', 'email' => 'user@example.com', 'reason' => 'invalid_password']);
Logger::security('csrf.failed', ['ip' => '10.0.0.1', 'route' => '/admin/delete']);
Logger::security('rate_limit.exceeded', ['ip' => '203.0.113.5', 'route' => '/api/login']);
```

Output in `security-YYYY-MM-DD.log` and `security.log`:

```
[2026-05-13 10:30:00] [SECURITY] auth.failed {"ip":"192.168.1.1","email":"user@example.com","reason":"invalid_password"}
```

### Slow Query Logging

Automatically flags requests that exceed the configured threshold.

```php
Logger::slowRequest('GET', '/api/reports', 200, 850.0);
// (not written because 850ms > 100ms default threshold)

Logger::slowRequest('GET', '/api/reports', 200, 15.0);
// (not written — below threshold)
```

Output in `slow-YYYY-MM-DD.log` and `slow.log`:

```
[2026-05-13 10:30:00] GET /api/reports 200 850.00ms (threshold: 100ms)
```

---

## Daily Log Rotation

Every log type has a **daily file** (`error-2026-05-13.log`, `request-2026-05-13.log`, etc.). The `error`, `slow`, and `security` types are additionally written to a non-dated main file (`error.log`, `slow.log`, `security.log`). When the main file exceeds 50 MB it is rotated with a timestamp suffix.

Old daily logs are cleaned up automatically based on `LOG_RETENTION_DAYS`:

```
storage/logs/
  error-2026-04-01.log  ← deleted (older than 30 days)
  error-2026-05-13.log  ← kept
  request-2026-05-13.log
  debug-2026-05-13.log
  slow-2026-05-13.log
  security-2026-05-13.log
  error.log              ← persistent main file
  slow.log
  security.log
```

---

## Log Sanitization

### Sensitive Field Redaction

The logger automatically redacts sensitive data using regex patterns. The following are caught:

- **Headers:** `authorization`, `cookie`, `x-api-key`, `x-csrf-token`, `session-id`
- **Body fields:** `password`, `token`, `otp`, `secret`, `credit_card`, `card_number`, `cvv`, `pin`, `ssn`, `passport`
- **Query params:** `token`, `key`, `secret`, `api_key`, `code`
- **Credit card numbers:** any 13-19 digit sequence → `[REDACTED-CARD]`
- **JSON patterns:** all of the above matched inside JSON strings
- **Key-value patterns:** `password=secret123`, `Authorization: Bearer xyz`, etc.

```php
Logger::error("Connecting with password=supersecret and token=abc123def456");
// Stored as: Connecting with password=[REDACTED] and token=[REDACTED]
```

### Custom Sanitize Configuration

```php
Logger::setSanitizeConfig([
    'headers' => ['authorization', 'x-api-key', 'custom-sensitive-header'],
    'body'    => ['password', 'token', 'ssn', 'custom-field'],
    'query'   => ['token', 'code'],
]);
```

### JSON Body Sanitization (new in v0.24)

Recursively sanitizes JSON request/response bodies before logging traces:

```php
$sanitized = Logger::sanitizeJsonBody('{"user":{"password":"secret123","email":"test@example.com"}}');
// {"user":{"password":"[REDACTED]","email":"test@example.com"}}
```

```php
$headers = Logger::sanitizeHeaders(['Authorization' => 'Bearer xyz', 'X-Custom' => 'visible']);
// ['Authorization' => '[REDACTED]', 'X-Custom' => 'visible']
```

---

## Trace Logging

Trace logging captures full request/response lifecycle for debugging. Each trace is stored as a separate JSON file in `storage/logs/traces/`.

```php
Logger::trace('trace-abc-123', [
    'request_headers' => ['Authorization' => 'Bearer xyz'],
    'request_body'    => '{"email":"test@test.com","password":"secret"}',
    'response_body'   => '{"token":"abc123","user":{"email":"test@test.com"}}',
    'query_params'    => ['token' => 'abc', 'page' => 1],
    'duration_ms'     => 120.5,
]);
```

Automatically sanitizes `request_headers`, `request_body`, `response_body`, and `query_params`.

Old trace files are removed based on `LOG_RETENTION_DAYS`.

### CLI: Trace Listing & Replay

```bash
# List recent traces
php siro log:trace

# Replay a specific trace
php siro log:replay trace-abc-123

# Replay against a different environment
php siro log:replay trace-abc-123 --target=https://staging.example.com

# Replay with modified headers
php siro log:replay trace-abc-123 --override="Authorization: Bearer new-token"
```

---

## Slow Query Logging

Set the threshold in your `.env`:

```dotenv
DB_SLOW_QUERY_THRESHOLD=200
```

The logger flags any request slower than the threshold when you call `slowRequest()`:

```php
Logger::slowRequest('POST', '/api/orders', 201, 1500.0);
```

```bash
# View slow log statistics
php siro log:slow --stats

# Watch slow logs in real time
php siro log:tail --slow
```

---

## Log Directory Protection

On `boot()`, the logger automatically creates access-denial files in the log directory to prevent direct web access:

### `.htaccess` (Apache)

```
Deny from all
```

### `web.config` (IIS)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <security>
            <requestFiltering>
                <denyUrlSequences>
                    <add sequence="."/>
                </denyUrlSequences>
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>
```

### `nginx-deny.conf` (NGINX)

```
deny all;
```

These are created only if they do not already exist, so custom configurations are preserved.

---

## Helper Methods

```php
Logger::sanitize($message);         // redact sensitive data from a string
Logger::sanitizeHeaders($headers);  // redact sensitive headers from an array
Logger::sanitizeJsonBody($json);    // recursively redact sensitive JSON fields
Logger::escapeLog($message);        // replace newlines with \n
Logger::getLogDir();                // absolute path to the log directory
```
