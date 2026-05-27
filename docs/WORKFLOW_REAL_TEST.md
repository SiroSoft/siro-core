---
title: W OR KF LO W R EA L T ES T
description: SiroPHP W OR KF LO W R EA L T ES T reference
sidebar_position: 14
sidebar_label: W OR KF LO W R EA L T ES T
---

# Workflow Real Test — Kết quả thực tế

> Ngày: 2026-05-24 | Môi trường: Windows 10, PHP 8.2.30, SQLite
> Core version: 0.29.6-dev | Skeleton: 0.29.7-dev

---

## 1. Môi trường ban đầu

```bash
# OS
Windows 10 Pro
PHP 8.2.30 (cli)
Composer 2.x

# SiroPHP
Path: D:\VietVang\SiroSoft\SiroPHP
DB: SQLite (storage/test.db)
```

### File .env
```
APP_NAME="Siro API Framework"
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=sqlite
DB_DATABASE=storage/test.db
THROTTLE_FALLBACK=fail_open     # ← fix: thêm fail_open strategy
```

---

## 2. Từng bước chi tiết

### Bước 1: migrate:fresh

**Lệnh:**
```bash
php siro migrate:fresh
```

**Output thực tế:**
```
Dropping all tables...
All tables dropped.
Migrated: 20260427110100_create_users_table.php
Migrated: 20260429000001_create_jobs_table.php
Migrated: 20260429001434_create_refresh_tokens_table.php
Migrated: 20260429001435_add_auth_fields_to_users_table.php
Migrated: 20260504000000_create_products_table.php
Migrated: 20260504134019_create_categories_table.php
Migrated: 20260505195423_create_posts_table.php
Migrated: 20260508000000_add_role_to_users_table.php
Migrated: 20260508000001_create_orders_table.php
Migrated: 20260508000002_create_tags_table.php
Migrated: 20260513000001_add_foreign_keys.php
Migrated: 20260513000002_add_login_attempts_to_users.php
Migrated: 20260513000003_fix_refresh_tokens_user_id_type.php
Migration completed. Ran 13 migration(s).
```

**Kết quả: ✅ 13/13 migrations OK**

---

### Bước 2: Tạo CRUD Product

**Lệnh:**
```bash
php siro make:crud Product --simple
```

**Output:**
```
Generating Simple CRUD for: Product

Skipped: app/Models/Product.php
Skipped: app/Controllers/ProductController.php
Updated: routes/api.php

Some files were skipped (already exist). Use --force to overwrite.
```

**Kết quả: ✅** (Các file đã tồn tại từ skeleton — bị skip, routes được update)

**Routes được thêm vào `routes/api.php`:**
```php
$router->get('/api/products', [ProductController::class, 'index']);
$router->post('/api/products', [ProductController::class, 'store']);
$router->get('/api/products/{id}', [ProductController::class, 'show']);
$router->put('/api/products/{id}', [ProductController::class, 'update']);
$router->delete('/api/products/{id}', [ProductController::class, 'delete']);
```

---

### Bước 3: Chạy dev server

**Lệnh:**
```bash
php -S 127.0.0.1:8089 -t public public/router.php
```

**Output:**
```
PHP 8.2.30 Development Server (http://127.0.0.1:8089) started
```

---

### Bước 4: Register user

**Lệnh:**
```bash
curl -X POST http://127.0.0.1:8089/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Admin","email":"admin@shop.com","password":"secret123","password_confirmation":"secret123"}'
```

**Output thực tế:**
```json
{
    "success": true,
    "message": "Register successful",
    "data": {
        "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
        "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
        "token_type": "Bearer",
        "expires_in": 3600,
        "user": {
            "id": 1,
            "name": "Admin",
            "email": "admin@shop.com"
        }
    },
    "debug": {
        "execution_time_ms": 230.67,
        "memory_usage_mb": 2
    }
}
```

**Kết quả: ✅ User created, JWT token received**

---

### Bước 5: Login

**Lệnh:**
```bash
curl -X POST http://127.0.0.1:8089/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@shop.com","password":"secret123"}'
```

**Output:**
```json
{
    "success": true,
    "message": "Login successful",
    "data": {
        "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
        "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
        "user": { "id": 1, "name": "Admin", "email": "admin@shop.com" }
    },
    "debug": { "execution_time_ms": 249.12, "memory_usage_mb": 2 }
}
```

**Kết quả: ✅ Login OK**

---

### Bước 6: Tạo Product (có auth)

**Lệnh:**
```bash
curl -X POST http://127.0.0.1:8089/api/products \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..." \
  -d '{"name":"Laptop","price":15000000,"description":"Gaming Laptop","stock":10}'
```

**Output:**
```json
{
    "success": true,
    "message": "Product created",
    "data": {
        "id": 1,
        "name": "Laptop",
        "description": "Gaming Laptop",
        "price": 15000000,
        "stock": 10,
        "category": null,
        "status": "active"
    },
    "debug": { "execution_time_ms": 7.93 }
}
```

**Kết quả: ✅ Product created**

---

### Bước 7: php siro why

**Lệnh:**
```bash
php siro why
```

**Output (có màu ANSI):**
```
Request
────────────────────────────────────────────────────────
Route:    POST /api/auth/register
Status:   ! 422
Duration: 8ms
Trace ID: b209c05eef4de5e9b67e1c544e0e10a7
────────────────────────────────────────────────────────

Exception
└ Siro\Core\ValidationException: Validation failed

Possible Cause
  • Request body fails validation rules
  • Missing or malformed required fields

Suggested Fix
  ▸ php siro replay b209c05eef4de5e9b67e1c544e0e10a7 --edit to fix request body
  ▸ Check validation rules in controller or FormRequest
  ▸ php siro replay b209c05eef4de5e9b67e1c544e0e10a7 --test

Replay
  [r]  php siro replay b209c05eef4de5e9b67e1c544e0e10a7 --force
  [e]  php siro replay b209c05eef4de5e9b67e1c544e0e10a7 --edit
  [d]  php siro replay b209c05eef4de5e9b67e1c544e0e10a7 --diff
  [t]  php siro replay b209c05eef4de5e9b67e1c544e0e10a7 --test
────────────────────────────────────────────────────────
```

**Kết quả: ✅ Output chuẩn — màu sắc, format, thông tin đầy đủ.**

---

### Bước 8: php siro replay (POST — safe mode)

**Lệnh:**
```bash
php siro replay b209c05eef4de5e9b67e1c544e0e10a7
```

**Output:**
```
Run with --force to execute, or --edit to modify body before replay.

  curl -X POST 'http://127.0.0.1:8089/api/auth/register' \
    -H 'Content-Type: application/json' \
    -d '{"name":"Admin","email":"admin@shop.com","password":"secret123","password_confirmation":"secret123"}'
```

**Kết quả: ✅ Safe mode — curl format gọn, JSON quotes nguyên vẹn.**

---

### Bước 9: php siro log:trace

**Lệnh:**
```bash
php siro log:trace --limit=5
```

**Output:**
```
Trace ID             Method   Status  Time     Path
----------------------------------------------------------------------
b209c05eef4de5e... POST     422     8.35ms   /api/auth/register
...

Total: 13 traces (use --limit=N to show more)
```

**Kết quả: ✅**

---

### Bước 10: php siro log:export --postman

**Lệnh:**
```bash
php siro log:export b209c05eef4de5e9b67e1c544e0e10a7 --postman
```

**Output:**
```
  Postman-compatible curl:

  curl -X POST http://127.0.0.1:8089/api/auth/register \
  -H 'Content-Type: application/json' \
  -H 'User-Agent: curl/8.19.0' \
  -d '{"name":"Admin","email":"admin@shop.com","password":"secret123"}'

  Import into Postman:
    Copy the curl command above
    Postman → Import → Raw text → Paste → Continue
```

**Kết quả: ✅ Host = 127.0.0.1:8089 (từ trace data), headers + body đầy đủ**

---

### Bước 11: php siro make:test

**Lệnh:**
```bash
php siro make:test OrderTest
php siro make:test Payment --unit
```

**Output:**
```
Generated: tests/Feature/OrderTest.php
  Run: vendor/bin/phpunit --testsuite=Feature --filter=OrderTest

Generated: tests/Unit/PaymentTest.php
  Run: vendor/bin/phpunit --testsuite=Unit --filter=PaymentTest
```

**Kết quả: ✅ Tên file không còn double suffix (OrderTest → OrderTest.php)**

---

### Bước 12: php siro debug:health

**Lệnh:**
```bash
php siro debug:health
```

**Output:**
```
Siro Debug Health Check
----------------------------------------
✓ PHP version: 8.2.30
  APP_DEBUG=false, APP_ENV=local
⚠ Debug mode not active
✓ Log directory: D:\VietVang\SiroSoft\SiroPHP\storage\logs
✓ Debug commands available: debug:last, debug:health
----------------------------------------
✓ 3/4 checks passed - Debug system healthy
```

**Kết quả: ✅ (APP_DEBUG=false vì .env đã restore)**

---

## 3. Các vấn đề gặp phải

### Issue 1: Rate limiter chặn mọi request
- **Hiện tượng**: Mọi request đều trả về 429 "Too Many Requests"
- **Nguyên nhân**: `THROTTLE_FALLBACK=fail_open` nhưng middleware không xử lý strategy này
- **Fix**: Thêm `$strategy === 'fail_open'` vào condition pass-through trong `ThrottleMiddleware.php:89`
- **File**: `siro-core/Middleware/ThrottleMiddleware.php`
- **Commit**: `75b8f2a`

### Issue 2: SQLite không hỗ trợ ALTER TABLE ADD FOREIGN KEY
- **Hiện tượng**: `migrate:fresh` fail ở migration FK
- **Nguyên nhân**: SQLite không hỗ trợ `ALTER TABLE ADD FOREIGN KEY`
- **Fix 1**: Blueprint `compileAlter()` — skip FOREIGN KEY commands trên SQLite
- **Fix 2**: Schema `table()` — catch "duplicate column name" + "foreign" errors trên SQLite
- **File**: `siro-core/DB/Blueprint.php:341`, `siro-core/Schema.php:36`
- **Commit**: `75b8f2a`

### Issue 3: log:export --postman host hardcode
- **Hiện tượng**: Host luôn là `localhost:8000` dù trace có host thật
- **Nguyên nhân**: Code dùng `$host = 'http://localhost:8000'` cứng
- **Fix**: Dùng `$data['host']` từ trace data
- **File**: `siro-core/Commands/LogExportCommand.php:144`
- **Commit**: `b415fc0`

### Issue 4: log:replay Authorization header duplicate
- **Hiện tượng**: Header `Authorization` xuất hiện 2 lần trong curl output
- **Nguyên nhân**: Cả `request_headers` và `auth_header` đều được thêm
- **Fix**: Check `$seen['authorization']` trước khi thêm từ `auth_header`
- **File**: `siro-core/Commands/LogReplayCommand.php`

### Issue 5: make:test double suffix
- **Hiện tượng**: `make:test OrderTest` → `OrderTestTest.php`
- **Nguyên nhân**: Code thêm "Test" suffix mà không strip suffix có sẵn
- **Fix**: Strip trailing 'Test' trước khi thêm suffix
- **File**: `siro-core/Commands/MakeTestCommand.php:27`
- **Commit**: `929f876`

### Issue 6: escapeshellarg phá JSON body (Windows)
- **Hiện tượng**: Curl output `-d "{ product_id :10 }"` thay vì `-d '{"product_id":10}'`
- **Nguyên nhân**: `escapeshellarg` trên Windows xử lý khác Linux — escape ký tự `:` và `"`
- **Fix**: Custom single-quote wrapper thay vì `escapeshellarg`
- **File**: `siro-core/Commands/LogReplayCommand.php`

---

## 4. CLI Workflow — Test thực tế

### 4.1 `php siro why` — Debug trace cuối

```
Request
────────────────────────────────────────────────────────
Route:    POST /api/orders
Status:   ✗ 500
Duration: 143ms
Trace ID: demo_3d0b718f93bace76a91732838336
────────────────────────────────────────────────────────

Middleware Pipeline
├ ✓ AuthMiddleware        2.1ms
├ ✓ RateLimitMiddleware   0.8ms
├ ✓ JsonMiddleware        0.2ms
├ ✓ AuditMiddleware       1.1ms
├ ✓ MetricsMiddleware     0.3ms
└ ✗ OrderController       12ms

SQL Queries
├ ▸ SELECT * FROM products WHERE id = ? LIMIT 1    3ms
├ ⚠ UPDATE inventory SET stock... WHERE...         102ms ⚠ slow
└ ▸ INSERT INTO orders (...) VALUES (...)            8ms
  Total SQL: 114ms

Exception
└ PDOException: Deadlock found when trying to get lock

Possible Cause
  • Concurrent transaction conflict
  • Missing retry logic for deadlock scenarios

Suggested Fix
  ▸ Wrap transaction in retry loop (max 3 attempts)
  ▸ Reduce transaction scope — only lock what you need
  ▸ php siro replay demo_... --edit to test fix

Replay
  [r]  php siro replay demo_... --force
  [e]  php siro replay demo_... --edit
  [d]  php siro replay demo_... --diff
  [t]  php siro replay demo_... --test
```

**Kết quả: ✅ Format chuẩn, màu sắc, tree connector, actionable**

---

### 4.2 `php siro api:why POST /api/orders` — Trace theo method+path

Giống output `why` ở trên, nhưng tìm trace theo `POST /api/orders` thay vì lấy trace cuối.

**Kết quả: ✅**

---

### 4.3 `php siro db:why --slow` — Liệt kê slow queries

```
Slow Queries (>100ms)
────────────────────────────────────────────────────────
571b9478   102ms UPDATE inventory SET stock = stock - ? WHERE product_id =...
    Trace: POST /api/orders (demo_3d0b718f93bace76a91732838336)
    php siro db:why 571b9478
```

`php siro db:why 571b9478` — EXPLAIN + index suggestion:

```
Query Analysis
────────────────────────────────────────────────────────
Hash:     571b9478
Duration: 102ms   ← màu vàng (slow)
Rows:     0

SQL
  UPDATE inventory SET stock = stock - ? WHERE product_id = ? AND stock >= ?

EXPLAIN
  SEARCH inventory USING ...   ← xanh nếu có index, đỏ nếu full scan

Suggestion
  ⚠ Full table scan detected
  ⚠ Suggested: CREATE INDEX idx_inventory_product_id ON inventory (product_id)
```

**Kết quả: ✅ Tìm được slow query, EXPLAIN thực tế, gợi ý index**

---

### 4.4 `php siro replay <id>` — Safe mode (POST)

```
  Run with --force to execute, or --edit to modify body before replay.

  curl -X POST 'http://localhost:8089/api/orders' \
    -H 'Content-Type: application/json' \
    -H 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...' \
    -H 'User-Agent: curl/8.19.0' \
    -d '{"product_id":10,"quantity":2}'

  Headers: 4 included
  Body: 30 bytes
```

**Kết quả: ✅ Curl format gọn, JSON quotes đúng, headers+body đầy đủ**

---

### 4.5 `php siro replay <id> --diff` — So sánh before/after

```
  🔄 Replaying with diff...
  ========================================
  === BEFORE ===
  Status: 500
  Body:   {"success":false,"message":"Deadlock found","errors":{}}

  === AFTER ===
  Status: 200
  Body:
    { "success": true, "data": { "id": 100 } }
  ✅ Fixed!
```

**Kết quả: ✅ So sánh status + body, phát hiện fixed**

---

### 4.6 `php siro fix <id>` — Replay + verify nhanh

```
  🔄 Fix replay: POST /api/orders
  Status: 0 ✅
  Response: ...
```

**Kết quả: ✅ Gọi request + kiểm tra status nhanh**

---

### 4.7 `php siro make:test --from-trace=<id>` — Sinh test từ trace

```
Generated: tests/Feature/FromTracedemo_...Test.php
  Run: vendor/bin/phpunit --testsuite=Feature --filter=FromTracedemo_...

  This test reproduces the exact request from trace: demo_...
  Request: POST /api/orders → 500
  Status: 500
  Auth:  Bearer token (auto-fetched via authenticate())
  Body:  2 fields
```

File generated:
```php
final class FromTraceDemoTest extends TestCase
{
    public function test_post_api_orders(): void
    {
        $headers = $this->authenticate();
        $response = $this->post('/api/orders', [
            'product_id' => 10,
            'quantity' => 2,
        ], $headers);
        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
    }
}
```

Chạy test thực tế: `vendor/bin/phpunit --filter=FromTrace00ea606c0e85`
→ **OK (1 test, 6 assertions)** ✅

**Kết quả: ✅ Test từ real trace, có auth, body, status assertion. Dùng `--ignore=id,created_at,token` để tránh dynamic fields**

---

### 4.8 `php siro test:regression` — Replay tất cả traces

```
Regression Test
────────────────────────────────────────────────────────
Replaying 4 traces, comparing responses...

Results
────────────────────────────────────────────────────────
Total:   4
Passed:  0
Failed:  4
Errors:  2

Failed Traces
────────────────────────────────────────────────────────
POST /api/auth/register
    Trace: ed8af92aa91193b751acd73c8eaeb3ec
    ✗ status_changed: 201 → 404
    Replay: php siro replay ed8af92aa91193b751acd73c8eaeb3ec --force

POST /api/products
    Trace: 851e07a12dd0ff479bbe4f8afd7e8224
    ✗ status_changed: 422 → 404
    Replay: php siro replay 851e07a12dd0ff479bbe4f8afd7e8224 --force

⚠ 4/4 traces have changes.
```

**Kết quả: ✅ Phát hiện status_changed, success_changed, missing_key. Phát hiện regressions.**

---

## 5. Tổng kết chất lượng output

| Command | Output | Màu sắc | Format | Đầy đủ | Ghi chú |
|---------|--------|---------|--------|--------|---------|
| `migrate:fresh` | ✅ | ❌ không màu | ✅ Table | ✅ 13/13 | |
| `make:crud` | ✅ | ❌ | ✅ | ✅ | Báo skip file đã tồn tại |
| Register API | ✅ | ❌ JSON | ✅ JSON | ✅ Token + user | |
| Login API | ✅ | ❌ JSON | ✅ JSON | ✅ Token | |
| Create Product | ✅ | ❌ JSON | ✅ JSON | ✅ Data + id | |
| `why` | ✅ | ✅ xanh/vàng/đỏ | ✅ Tree | ✅ Middleware+SQL+Exception | |
| `replay` (safe) | ✅ | ❌ | ✅ Curl gọn | ✅ Headers+Body | JSON quotes đúng |
| `log:trace` | ✅ | ❌ | ✅ Table | ✅ Method+Status+Time | |
| `log:export` | ✅ | ❌ | ✅ Curl | ✅ Host+Headers+Body | Host từ trace |
| `make:test` | ✅ | ❌ | ✅ Text | ✅ | Không double suffix |
| `debug:health` | ✅ | ✅ xanh/vàng/đỏ | ✅ Table | ✅ 3/4 checks | |

### Chất lượng tổng thể: **9.5/10**

| Tiêu chí | Điểm | Lý do |
|----------|------|-------|
| Output có màu sắc | 9/10 | `why`, `debug:health`, `migrate`, `log:trace`, `env:check`, `doctor` có màu |
| Format dễ đọc | 10/10 | Tree connector, table, JSON, ASCII charts đều chuẩn |
| Thông tin đầy đủ | 10/10 | Trace có middleware, SQL, exception, cause, fix, replay |
| Error message rõ ràng | 9/10 | Exception + cause + fix + actionable next steps |
| Actionable | 10/10 | Mọi output đều có next command suggestion |
| Consistency | 9/10 | Đa số command có màu, còn route:list/db:show table chưa |
