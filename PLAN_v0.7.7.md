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

## 📋 Priority 1: Unit Testing Infrastructure 🔴

### 1.1 Setup PHPUnit Configuration
**Files to Create:**
- `phpunit.xml` - PHPUnit configuration
- `tests/TestCase.php` - Base test case class
- `tests/bootstrap.php` - Test bootstrap

**Estimated Time:** 2-3 hours

**Example phpunit.xml:**
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

### 1.2 Core Component Unit Tests
**Files to Create:**

#### Validator Tests
- `tests/unit/ValidatorTest.php`
- Test all validation rules:
  - required, email, min, max
  - unique, exists, confirmed, in
  - nullable, date, url, ip, uuid

**Test Cases (Expected: ~20 tests):**
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

**Test Cases (Expected: ~15 tests)**

#### Response Tests
- `tests/unit/ResponseTest.php`
- Test response methods:
  - json(), error(), success()
  - paginated(), created(), noContent()
  - Status codes and headers

**Test Cases (Expected: ~12 tests)**

#### Router Tests
- `tests/unit/RouterTest.php`
- Test routing:
  - Route registration (GET, POST, PUT, DELETE)
  - Route parameters
  - Middleware execution
  - Auto OPTIONS handling
  - Route matching

**Test Cases (Expected: ~18 tests)**

**Total Estimated Time:** 8-10 hours

---

### 1.3 Model Layer Tests
**Files to Create:**
- `tests/unit/ModelTest.php`
- Test Model features:
  - find(), where(), create(), update(), delete()
  - Relationships (hasMany, belongsTo)
  - Soft deletes
  - Attribute casting
  - Hidden fields

**Test Cases (Expected: ~25 tests)**

**Estimated Time:** 6-8 hours

---

### 1.4 Database QueryBuilder Tests
**Files to Create:**
- `tests/unit/QueryBuilderTest.php`
- Test query operations:
  - select(), where(), orderBy()
  - insert(), update(), delete()
  - paginate(), first(), get()
  - Cache functionality

**Test Cases (Expected: ~20 tests)**

**Estimated Time:** 5-6 hours

---

### 1.5 Resource Tests
**Files to Create:**
- `tests/unit/ResourceTest.php`
- Test resource mapping:
  - make() with Model
  - make() with array
  - collectionOf() with field selection
  - Custom toArray() transformation

**Test Cases (Expected: ~10 tests)**

**Estimated Time:** 3-4 hours

---

**Priority 1 Total:** ~24-31 hours of work

---

## 📋 Priority 2: Fix Integration Tests 🟡

### 2.1 Fix Protected Route Issues
**Current Status:** 12/14 tests pass (Tests 9 & 11 fail)

**Issues to Fix:**
1. **Test 9:** "Protected route accessible with valid token" - Invalid user data
   - Root Cause: User model hidden fields might be hiding email
   - Fix: Review User model's $hidden array in SiroPHP app
   
2. **Test 11:** "Logout revokes token" - 401 error
   - Root Cause: Token version check in AuthMiddleware
   - Fix: Ensure token_version is properly incremented on logout

**Files to Check:**
- `SiroPHP/app/Models/User.php` - hidden/fillable config
- `SiroPHP/app/Middleware/AuthMiddleware.php` - token validation logic
- `SiroPHP/app/Controllers/AuthController.php` - logout implementation

**Estimated Time:** 2-3 hours

---

### 2.2 Add More Integration Tests
**New Tests to Add:**
- File upload integration test
- Model relationships integration test
- Soft deletes integration test
- Resource auto-map integration test
- route:list command test

**Estimated Time:** 4-5 hours

---

**Priority 2 Total:** ~6-8 hours

---

## 📋 Priority 3: Extended Validation Rules 🟡

### 3.1 Add Missing Rules
**Rules to Add:**
- `nullable` - Field can be null
- `sometimes` - Validate only if present
- `required_if:field,value` - Conditional required
- `required_unless:field,value` - Conditional required
- `date` - Must be valid date
- `date_format:Y-m-d` - Date format validation
- `url` - Valid URL format
- `ip` - Valid IP address
- `uuid` - Valid UUID format
- `min_length:n` - Minimum string length
- `max_length:n` - Maximum string length

**File to Modify:**
- `Validator.php` - Add new rule methods

**Estimated Time:** 4-5 hours

---

### 3.2 Custom Validation Messages
**Feature:** Allow custom error messages per rule

**Example:**
```php
$validated = $request->validate([
    'email' => 'required|email',
], [
    'email.required' => 'Please provide your email address',
    'email.email' => 'The email format is invalid',
]);
```

**Estimated Time:** 2-3 hours

---

**Priority 3 Total:** ~6-8 hours

---

## 📋 Priority 4: Error Handling Improvements 🟢

### 4.1 Specific Exception Classes
**Files to Create:**
- `Exceptions/NotFoundException.php` (404)
- `Exceptions/UnauthorizedException.php` (401)
- `Exceptions/ForbiddenException.php` (403)
- `Exceptions/ValidationException.php` (already exists)

**Update App.php:**
- Add specific catch blocks for each exception type
- Return appropriate HTTP status codes

**Estimated Time:** 3-4 hours

---

### 4.2 Better Error Messages
**Improvements:**
- Include stack trace in debug mode
- Better validation error formatting
- Helpful error suggestions

**Estimated Time:** 2-3 hours

---

**Priority 4 Total:** ~5-7 hours

---

## 📋 Priority 5: Documentation 🟢

### 5.1 Update README Examples
**Add sections for:**
- Unit testing guide
- Custom validation rules
- Error handling best practices
- Relationship examples (more complex scenarios)

**Estimated Time:** 3-4 hours

---

### 5.2 Create Testing Guide
**File:** `docs/TESTING.md`
- How to write unit tests
- How to write integration tests
- Mocking examples
- Test database setup

**Estimated Time:** 2-3 hours

---

**Priority 5 Total:** ~5-7 hours

---

## 📊 Timeline Estimate

### Conservative Approach (Quality First):
- **Week 1:** Priority 1 (Unit Tests) - 24-31 hours
- **Week 2:** Priority 2 + 3 (Fix tests + Validation) - 12-16 hours
- **Week 3:** Priority 4 + 5 (Error handling + Docs) - 10-14 hours
- **Total:** 3 weeks part-time or 1.5 weeks full-time

### Aggressive Approach (Fast Release):
- **Focus:** Only Priority 1 (Unit Tests)
- **Timeline:** 1 week
- **Defer:** Priorities 2-5 to v0.8.0

### Balanced Approach (Recommended) ⭐:
- **Days 1-3:** Priority 1 (Core unit tests) - Validator, Request, Response, Router
- **Days 4-5:** Priority 2 (Fix integration tests)
- **Days 6-7:** Priority 3 (Extended validation rules)
- **Skip:** Priority 4-5 (defer to v0.8.0)
- **Total:** 1 week focused work

---

## 🎯 Recommended v0.7.7 Scope

**My Recommendation: Balanced Approach**

### Include in v0.7.7:
1. ✅ PHPUnit setup and configuration
2. ✅ Unit tests for core components (Validator, Request, Response, Router)
3. ✅ Fix integration tests (reach 14/14 pass)
4. ✅ Extended validation rules (nullable, date, url, ip, uuid, etc.)
5. ✅ Basic Model tests

### Defer to v0.8.0:
- Advanced error handling exceptions
- Custom validation messages
- Comprehensive documentation
- Performance profiling tools

---

## 📈 Success Metrics for v0.7.7

### Code Coverage Targets:
- **Validator:** 90%+ coverage
- **Request:** 85%+ coverage
- **Response:** 85%+ coverage
- **Router:** 80%+ coverage
- **Model:** 75%+ coverage
- **Overall:** 80%+ average

### Test Counts:
- **Unit Tests:** 80-100 tests
- **Integration Tests:** 14-18 tests
- **Total:** 95-118 tests

### Pass Rate:
- **Unit Tests:** 100% pass
- **Integration Tests:** 100% pass (14/14)

---

## 🚀 Implementation Plan

### Day 1: Setup & Validator Tests
- [ ] Create phpunit.xml configuration
- [ ] Create TestCase base class
- [ ] Write ValidatorTest.php (20 tests)
- [ ] Run tests, verify passing

### Day 2: Request & Response Tests
- [ ] Write RequestTest.php (15 tests)
- [ ] Write ResponseTest.php (12 tests)
- [ ] Fix any issues found

### Day 3: Router & Model Tests
- [ ] Write RouterTest.php (18 tests)
- [ ] Write ModelTest.php (25 tests)
- [ ] Run full test suite

### Day 4: Fix Integration Tests
- [ ] Debug Test 9 failure (protected routes)
- [ ] Debug Test 11 failure (logout)
- [ ] Verify 14/14 pass

### Day 5: Extended Validation
- [ ] Add nullable, sometimes rules
- [ ] Add date, url, ip, uuid rules
- [ ] Add conditional required rules
- [ ] Test new rules

### Day 6: Polish & Documentation
- [ ] Review code quality
- [ ] Update README with testing info
- [ ] Create basic testing guide
- [ ] Final test run

### Day 7: Release Prep
- [ ] Update composer.json to 0.7.7
- [ ] Update README version
- [ ] Create RELEASE_v0.7.7.md
- [ ] Commit, push, tag

---

## 💡 Key Considerations

### 1. Test Quality Over Quantity
- Focus on meaningful tests, not just coverage numbers
- Test edge cases and error conditions
- Use descriptive test names

### 2. Mock External Dependencies
- Mock database connections
- Mock file system operations
- Mock HTTP requests

### 3. Keep Tests Fast
- Use in-memory SQLite for database tests
- Avoid real file I/O when possible
- Parallelize tests if needed

### 4. CI/CD Integration
- Setup GitHub Actions for automated testing
- Run tests on every PR
- Block merge if tests fail

---

## 📦 Files Expected in v0.7.7

### New Files (~15):
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

### Modified Files (~5):
1. `Validator.php` - Add new rules
2. `App.php` - Better error handling
3. `README.md` - Update version and testing section
4. `composer.json` - Version bump
5. `SiroPHP` app files - Fix integration test issues

---

## 🎊 Expected Outcome

After v0.7.7 release:
- ✅ Comprehensive test coverage (80%+)
- ✅ All integration tests passing (14/14)
- ✅ Extended validation rules available
- ✅ Better developer confidence
- ✅ Easier to maintain and extend
- ✅ Ready for v0.8.0 major features

---

## 🔮 Looking Ahead to v0.8.0

After v0.7.7 stabilizes the codebase, v0.8.0 can focus on:
- Advanced query builder features (joins, subqueries)
- Event system for models
- Middleware improvements
- Performance optimization tools
- API documentation generation
- WebSocket support (maybe)

---

**Plan Created:** April 29, 2026  
**Next Action:** Start implementing Priority 1 (Unit Tests)  
**Estimated Completion:** 1 week
