# Kế Hoạch v0.7.7 - "Kiểm Thử & Hoàn Thiện"

**Mục Tiêu Release:** 1-2 tuần sau v0.7.6  
**Mục Đích:** Thêm unit tests, sửa lỗi integration tests, và hoàn thiện các tính năng hiện có

---

## 🎯 Tổng Quan

v0.7.7 sẽ tập trung vào **đảm bảo chất lượng** và **hoàn thiện các phần còn thiếu** từ v0.7.6:

1. ✅ Thêm unit tests toàn diện (mục tiêu: coverage 90%+)
2. ✅ Sửa lỗi integration tests (đạt 14/14 pass)
3. ✅ Thêm các validation rules còn thiếu
4. ✅ Cải thiện xử lý lỗi
5. ✅ Cải tiến tài liệu

---

## 📋 Ưu Tiên 1: Hạ Tầng Unit Testing 🔴

### 1.1 Cấu Hình PHPUnit
**Files cần tạo:**
- `phpunit.xml` - Cấu hình PHPUnit
- `tests/TestCase.php` - Base test case class
- `tests/bootstrap.php` - Test bootstrap

**Thời gian ước tính:** 2-3 giờ

**Ví dụ phpunit.xml:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory suffix="Test.php">tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

---

### 1.2 Unit Tests cho Core Components
**Files cần tạo:**

#### Validator Tests
- `tests/unit/ValidatorTest.php`
- Test tất cả validation rules:
  - required, email, min, max
  - unique, exists, confirmed, in
  - nullable, date, url, ip, uuid

**Test Cases (Dự kiến: ~20 tests):**
```php
test('required rule fails on empty', ...)
test('email rule validates format', ...)
test('unique rule checks database', ...)
test('confirmed rule matches confirmation field', ...)
```

#### Request Tests
- `tests/unit/RequestTest.php`
- Test input methods:
  - validated(), only()
  - int(), string(), bool(), array(), float()
  - queryInt(), queryString()
  - file() method

**Test Cases (Dự kiến: ~15 tests)**

#### Response Tests
- `tests/unit/ResponseTest.php`
- Test response methods:
  - json(), error(), success()
  - paginated(), created(), noContent()
  - Status codes và headers

**Test Cases (Dự kiến: ~12 tests)**

#### Router Tests
- `tests/unit/RouterTest.php`
- Test routing:
  - Route registration (GET, POST, PUT, DELETE)
  - Route parameters
  - Middleware execution
  - Auto OPTIONS handling
  - Route matching

**Test Cases (Dự kiến: ~18 tests)**

**Tổng thời gian ước tính:** 8-10 giờ

---

### 1.3 Model Layer Tests
**Files cần tạo:**
- `tests/unit/ModelTest.php`
- Test Model features:
  - find(), where(), create(), update(), delete()
  - Relationships (hasMany, belongsTo)
  - Soft deletes
  - Attribute casting
  - Hidden fields

**Test Cases (Dự kiến: ~25 tests)**

**Thời gian ước tính:** 6-8 giờ

---

### 1.4 Database QueryBuilder Tests
**Files cần tạo:**
- `tests/unit/QueryBuilderTest.php`
- Test query operations:
  - select(), where(), orderBy()
  - insert(), update(), delete()
  - paginate(), first(), get()
  - Cache functionality

**Test Cases (Dự kiến: ~20 tests)**

**Thời gian ước tính:** 5-6 giờ

---

### 1.5 Resource Tests
**Files cần tạo:**
- `tests/unit/ResourceTest.php`
- Test resource mapping:
  - make() với Model
  - make() với array
  - collectionOf() với field selection
  - Custom toArray() transformation

**Test Cases (Dự kiến: ~10 tests)**

**Thời gian ước tính:** 3-4 giờ

---

**Tổng Ưu Tiên 1:** ~24-31 giờ làm việc

---

## 📋 Ưu Tiên 2: Sửa Integration Tests 🟡

### 2.1 Sửa Lỗi Protected Routes
**Trạng thái hiện tại:** 12/14 tests pass (Tests 9 & 11 fail)

**Vấn đề cần sửa:**
1. **Test 9:** "Protected route accessible with valid token" - Invalid user data
   - Nguyên nhân: User model hidden fields có thể đang ẩn email
   - Fix: Review User model's $hidden array trong SiroPHP app
   
2. **Test 11:** "Logout revokes token" - 401 error
   - Nguyên nhân: Token version check trong AuthMiddleware
   - Fix: Đảm bảo token_version được increment đúng khi logout

**Files cần kiểm tra:**
- `SiroPHP/app/Models/User.php` - hidden/fillable config
- `SiroPHP/app/Middleware/AuthMiddleware.php` - token validation logic
- `SiroPHP/app/Controllers/AuthController.php` - logout implementation

**Thời gian ước tính:** 2-3 giờ

---

### 2.2 Thêm Integration Tests Mới
**Tests mới cần thêm:**
- File upload integration test
- Model relationships integration test
- Soft deletes integration test
- Resource auto-map integration test
- route:list command test

**Thời gian ước tính:** 4-5 giờ

---

**Tổng Ưu Tiên 2:** ~6-8 giờ

---

## 📋 Ưu Tiên 3: Extended Validation Rules 🟡

### 3.1 Thêm Rules Còn Thiếu
**Rules cần thêm:**
- `nullable` - Field có thể null
- `sometimes` - Chỉ validate nếu có mặt
- `required_if:field,value` - Conditional required
- `required_unless:field,value` - Conditional required
- `date` - Phải là date hợp lệ
- `date_format:Y-m-d` - Date format validation
- `url` - Valid URL format
- `ip` - Valid IP address
- `uuid` - Valid UUID format
- `min_length:n` - Minimum string length
- `max_length:n` - Maximum string length

**File cần sửa:**
- `Validator.php` - Thêm new rule methods

**Thời gian ước tính:** 4-5 giờ

---

### 3.2 Custom Validation Messages
**Tính năng:** Cho phép custom error messages per rule

**Ví dụ:**
```php
$validated = $request->validate([
    'email' => 'required|email',
], [
    'email.required' => 'Vui lòng nhập địa chỉ email',
    'email.email' => 'Định dạng email không hợp lệ',
]);
```

**Thời gian ước tính:** 2-3 giờ

---

**Tổng Ưu Tiên 3:** ~6-8 giờ

---

## 📋 Ưu Tiên 4: Cải Thiện Xử Lý Lỗi 🟢

### 4.1 Specific Exception Classes
**Files cần tạo:**
- `Exceptions/NotFoundException.php` (404)
- `Exceptions/UnauthorizedException.php` (401)
- `Exceptions/ForbiddenException.php` (403)
- `Exceptions/ValidationException.php` (đã tồn tại)

**Cập nhật App.php:**
- Thêm specific catch blocks cho từng exception type
- Return appropriate HTTP status codes

**Thời gian ước tính:** 3-4 giờ

---

### 4.2 Better Error Messages
**Cải tiến:**
- Include stack trace trong debug mode
- Better validation error formatting
- Helpful error suggestions

**Thời gian ước tính:** 2-3 giờ

---

**Tổng Ưu Tiên 4:** ~5-7 giờ

---

## 📋 Ưu Tiên 5: Tài Liệu 🟢

### 5.1 Cập Nhật README Examples
**Thêm sections cho:**
- Unit testing guide
- Custom validation rules
- Error handling best practices
- Relationship examples (complex scenarios)

**Thời gian ước tính:** 3-4 giờ

---

### 5.2 Tạo Testing Guide
**File:** `docs/TESTING.md`
- Cách viết unit tests
- Cách viết integration tests
- Mocking examples
- Test database setup

**Thời gian ước tính:** 2-3 giờ

---

**Tổng Ưu Tiên 5:** ~5-7 giờ

---

## 📊 Ước Tính Timeline

### Phương Án Conservative (Chất Lượng Là Trên Hết):
- **Tuần 1:** Ưu Tiên 1 (Unit Tests) - 24-31 giờ
- **Tuần 2:** Ưu Tiên 2 + 3 (Fix tests + Validation) - 12-16 giờ
- **Tuần 3:** Ưu Tiên 4 + 5 (Error handling + Docs) - 10-14 giờ
- **Tổng:** 3 tuần part-time hoặc 1.5 tuần full-time

### Phương Án Aggressive (Release Nhanh):
- **Tập trung:** Chỉ Ưu Tiên 1 (Unit Tests)
- **Timeline:** 1 tuần
- **Defer:** Ưu Tiên 2-5 sang v0.8.0

### Phương Án Balanced (Khuyến Nghị) ⭐:
- **Ngày 1-3:** Ưu Tiên 1 (Core unit tests) - Validator, Request, Response, Router
- **Ngày 4-5:** Ưu Tiên 2 (Fix integration tests)
- **Ngày 6-7:** Ưu Tiên 3 (Extended validation rules)
- **Bỏ qua:** Ưu Tiên 4-5 (defer sang v0.8.0)
- **Tổng:** 1 tuần làm việc tập trung

---

## 🎯 Phạm Vi Khuyến Nghị cho v0.7.7

**Khuyến nghị: Phương Án Balanced**

### Bao gồm trong v0.7.7:
1. ✅ PHPUnit setup và configuration
2. ✅ Unit tests cho core components (Validator, Request, Response, Router)
3. ✅ Fix integration tests (đạt 14/14 pass)
4. ✅ Extended validation rules (nullable, date, url, ip, uuid, etc.)
5. ✅ Basic Model tests

### Defer sang v0.8.0:
- Advanced error handling exceptions
- Custom validation messages
- Comprehensive documentation
- Performance profiling tools

---

## 📈 Success Metrics cho v0.7.7

### Code Coverage Targets:
- **Validator:** 90%+ coverage
- **Request:** 85%+ coverage
- **Response:** 85%+ coverage
- **Router:** 80%+ coverage
- **Model:** 75%+ coverage
- **Overall:** 80%+ average

### Số Lượng Tests:
- **Unit Tests:** 80-100 tests
- **Integration Tests:** 14-18 tests
- **Tổng:** 95-118 tests

### Pass Rate:
- **Unit Tests:** 100% pass
- **Integration Tests:** 100% pass (14/14)

---

## 🚀 Kế Hoạch Triển Khai

### Ngày 1: Setup & Validator Tests
- [ ] Tạo phpunit.xml configuration
- [ ] Tạo TestCase base class
- [ ] Viết ValidatorTest.php (20 tests)
- [ ] Chạy tests, verify passing

### Ngày 2: Request & Response Tests
- [ ] Viết RequestTest.php (15 tests)
- [ ] Viết ResponseTest.php (12 tests)
- [ ] Fix any issues found

### Ngày 3: Router & Model Tests
- [ ] Viết RouterTest.php (18 tests)
- [ ] Viết ModelTest.php (25 tests)
- [ ] Chạy full test suite

### Ngày 4: Fix Integration Tests
- [ ] Debug Test 9 failure (protected routes)
- [ ] Debug Test 11 failure (logout)
- [ ] Verify 14/14 pass

### Ngày 5: Extended Validation
- [ ] Thêm nullable, sometimes rules
- [ ] Thêm date, url, ip, uuid rules
- [ ] Thêm conditional required rules
- [ ] Test new rules

### Ngày 6: Polish & Documentation
- [ ] Review code quality
- [ ] Update README với testing info
- [ ] Tạo basic testing guide
- [ ] Final test run

### Ngày 7: Release Prep
- [ ] Update composer.json to 0.7.7
- [ ] Update README version
- [ ] Tạo RELEASE_v0.7.7.md
- [ ] Commit, push, tag

---

## 💡 Các Điểm Cần Lưu Ý

### 1. Chất Lượng Test Hơn Số Lượng
- Tập trung vào meaningful tests, không chỉ coverage numbers
- Test edge cases và error conditions
- Sử dụng descriptive test names

### 2. Mock External Dependencies
- Mock database connections
- Mock file system operations
- Mock HTTP requests

### 3. Giữ Tests Nhanh
- Sử dụng in-memory SQLite cho database tests
- Tránh real file I/O khi có thể
- Parallelize tests nếu cần

### 4. CI/CD Integration
- Setup GitHub Actions cho automated testing
- Chạy tests trên mọi PR
- Block merge nếu tests fail

---

## 📦 Files Dự Kiến trong v0.7.7

### Files Mới (~15):
1. `phpunit.xml`
2. `tests/bootstrap.php`
3. `tests/TestCase.php`
4. `tests/unit/ValidatorTest.php`
5. `tests/unit/RequestTest.php`
6. `tests/unit/ResponseTest.php`
7. `tests/unit/RouterTest.php`
8. `tests/unit/ModelTest.php`
9. `tests/unit/QueryBuilderTest.php`
10. `tests/unit/ResourceTest.php`
11. `tests/integration/FileUploadTest.php`
12. `tests/integration/RelationshipTest.php`
13. `tests/integration/SoftDeleteTest.php`
14. `docs/TESTING.md`
15. `RELEASE_v0.7.7.md`

### Files Sửa Đổi (~5):
1. `Validator.php` - Thêm new rules
2. `App.php` - Better error handling
3. `README.md` - Update version và testing section
4. `composer.json` - Version bump
5. `SiroPHP` app files - Fix integration test issues

---

## 🎊 Kết Quả Mong Đợi

Sau khi release v0.7.7:
- ✅ Comprehensive test coverage (80%+)
- ✅ Tất cả integration tests passing (14/14)
- ✅ Extended validation rules available
- ✅ Developer confidence cao hơn
- ✅ Dễ maintain và extend hơn
- ✅ Sẵn sàng cho v0.8.0 major features

---

## 🔮 Nhìn Về Tương Lai v0.8.0

Sau khi v0.7.7 ổn định codebase, v0.8.0 có thể tập trung vào:
- Advanced query builder features (joins, subqueries)
- Event system cho models
- Middleware improvements
- Performance optimization tools
- API documentation generation
- WebSocket support (có thể)

---

**Kế Hoạch Tạo:** 29/4/2026  
**Hành Động Tiếp Theo:** Bắt đầu implement Ưu Tiên 1 (Unit Tests)  
**Hoàn Thành Dự Kiến:** 1 tuần
