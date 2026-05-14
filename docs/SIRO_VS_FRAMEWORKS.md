# Siro vs PHP Frameworks — Objective Comparison

> Last updated: 2026-05-15 | Siro v0.26.0
> 
> Mục đích: Cung cấp thông tin khách quan giúp developers chọn framework phù hợp.
> Mỗi framework có triết lý riêng — không có "tốt nhất tuyệt đối".

---

## 1. Đặc tính cơ bản

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **Triết lý** | Debug-first, security-first | Ecosystem-first | Enterprise architecture | Micro-framework | Performance via C extension |
| **Phiên bản** | 0.26.0 | 11.x | 7.x | 4.x | 5.x |
| **PHP yêu cầu** | ≥ 8.2 | ≥ 8.1 | ≥ 8.1 | ≥ 8.0 | ≥ 7.2 |
| **Runtime dependencies** | **0** | ~60 | ~80 | **0** | ~5 |
| **Boot time (cold)** | **~1ms** | ~50ms | ~80ms | ~2ms | ~0.5ms |
| **Memory per request** | **~2KB** | ~8MB | ~12MB | ~4KB | ~1KB |
| **Kích thước codebase** | ~25K lines | ~800K lines | ~1.5M lines | ~15K lines | ~30K lines |
| **Ngôn ngữ** | PHP thuần | PHP thuần | PHP thuần | PHP thuần | **C extension** |

---

## 2. Static Analysis & Type Safety

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **PHPStan Level Max** | ✅ **0 errors** | ❌ ~200+ errors | ❌ ~500+ errors | ❌ N/A | ❌ N/A |
| **Psalm Level 1** | ✅ **0 errors** | ❌ | ❌ | ❌ | ❌ |
| **Strict typing** | ✅ `declare(strict_types=1)` toàn bộ | ⚠️ Partial | ✅ Hầu hết | ⚠️ | ❌ |
| **0 runtime deps → smaller attack surface** | ✅ | ❌ | ❌ | ✅ | ⚠️ C extension |

---

## 3. Security Features

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **JWT Access + Refresh tokens** | ✅ Built-in | 🔌 Passport | 🔌 LexikJWTAuth | 🔌 Third-party | 🔌 Third-party |
| **JWT algorithm pinning** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **JWT JTI blacklist** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Key rotation** | ✅ Version-tracked | ❌ Manual | ❌ Manual | ❌ | ❌ |
| **CSRF** | ✅ Session + double-submit | ✅ | ✅ | ❌ | ❌ |
| **CORS Middleware** | ✅ | 🔌 laravel-cors | 🔌 NelmioCors | ✅ | ❌ |
| **CSP Middleware** | ✅ Built-in | ❌ Built-in | ❌ | ❌ | ❌ |
| **Rate Limiting** | ✅ Redis + file fallback | ✅ | 🔌 | ❌ | ❌ |
| **Audit Logging** | ✅ Built-in `security.log` | ❌ | ❌ | ❌ | ❌ |
| **PII Masking** | ✅ Password, token, CC, OTP | ❌ | ❌ | ❌ | ❌ |
| **Input Validation** | ✅ 15+ rules, custom | ✅ | ✅ | ✅ | ✅ |
| **Upload Security** | ✅ Whitelist + MIME + blocked | ⚠️ Basic | ⚠️ Basic | ⚠️ Basic | ⚠️ |
| **RBAC** | ✅ Built-in | 🔌 Gates/Policies | 🔌 Voters | ❌ | 🔌 |
| **OWASP Top 10** | ✅ Mitigated by default | ⚠️ Partial | ⚠️ Partial | ❌ | ❌ |
| **SBOM (CycloneDX)** | ✅ Auto-generated | ❌ | ❌ | ❌ | ❌ |
| **SLSA Provenance** | ✅ CI workflow | ❌ | ❌ | ❌ | ❌ |
| **Gitleaks** | ✅ CI scan | ❌ | ❌ | ❌ | ❌ |
| **CodeQL** | ✅ CI workflow | ❌ | ❌ | ❌ | ❌ |
| **SAST + DAST** | ✅ CI jobs | ❌ | ❌ | ❌ | ❌ |

---

## 4. Security Audit

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **STRIDE Threat Model** | ✅ docs/THREAT_MODEL.md | ❌ | ❌ | ❌ | ❌ |
| **OWASP ASVS Checklist** | ✅ 90% L2 compliance | ❌ | ❌ | ❌ | ❌ |
| **Security Policy** | ✅ SECURITY.md | ✅ | ✅ | ❌ | ❌ |
| **composer audit** | ✅ 0 vulnerabilities | ⚠️ Phụ thuộc 60+ deps | ⚠️ 80+ deps | ✅ | ⚠️ |

---

## 5. Testing Infrastructure

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **Unit Tests** | 988 | 30,000+ | 50,000+ | ~200 | ~500 |
| **Fuzz Tests** | **17,851** | ❌ | ❌ | ❌ | ❌ |
| **DAST Tests** | 157 | ❌ | ❌ | ❌ | ❌ |
| **Total Tests** | 19,038 | ~30,000+ | ~50,000+ | ~200 | ~500 |
| **Test Failures** | **0** | ~0 | ~0 | ~0 | ~0 |
| **Mutation Testing** | ✅ Infection MSI ≥80% | ❌ | ❌ | ❌ | ❌ |
| **Chaos Engineering** | ✅ 7 scenarios | ❌ | ❌ | ❌ | ❌ |
| **Property-based Testing** | ✅ 25+ edge cases | ❌ | ❌ | ❌ | ❌ |
| **Database Testing** | ✅ SQLite :memory: | ✅ SQLite | ✅ | ❌ | ❌ |
| **Coverage Gate ≥80%** | ✅ CI enforced | ❌ | ❌ | ❌ | ❌ |

---

## 6. Debugging & Observability

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **Request Replay** | ✅ **Built-in** | ❌ | ❌ | ❌ | ❌ |
| **Trace ID** | ✅ W3C Trace Context | ❌ | ❌ | ❌ | ❌ |
| **CLI Replay** | ✅ `php siro replay` | ❌ | ❌ | ❌ | ❌ |
| **`X-Response-Time`** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **`X-Siro-Trace-Id`** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Slow Query Detection** | ✅ Configurable threshold | ✅ | ✅ | ❌ | ❌ |
| **Query Capture** | ✅ Debug mode | 🔌 Debugbar | 🔌 | ❌ | ❌ |
| **Structured Logging** | ✅ JSON, rotation, PII-safe | ✅ Monolog | ✅ Monolog | 🔌 | ❌ |
| **Prometheus Metrics** | ✅ Built-in `/metrics` | ❌ | ❌ | ❌ | ❌ |
| **Debug Toolbar** | 🔌 CLI-based (70 commands) | ✅ Telescope/Debugbar | ✅ Profiler | ❌ | ❌ |
| **Health Endpoint** | ✅ `GET /health` | ❌ | ❌ | ❌ | ❌ |
| **Graceful Shutdown** | ✅ SIGTERM/SIGINT | ❌ | ❌ | ❌ | ❌ |
| **CLI Commands** | **70** | 80+ | 50+ | ~10 | ~20 |
| **CLI Typo Suggestions** | ✅ Levenshtein | ❌ | ❌ | ❌ | ❌ |

---

## 7. Performance

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **Static route dispatch** | **0.002ms (488K/s)** | ~0.05ms | ~0.08ms | ~0.003ms | ~0.001ms |
| **Dynamic route dispatch** | **0.009ms** | ~0.1ms | ~0.15ms | ~0.005ms | ~0.002ms |
| **Cold boot** | **~1ms** | ~50ms | ~80ms | ~2ms | ~0.5ms |
| **JSON response** | **~0.004ms (2.97M/s)** | ~0.02ms | ~0.03ms | ~0.005ms | ~0.003ms |
| **Dependency autoload** | **0 classes** (0 deps) | ~600 classes | ~800 classes | **0** | ~50 classes |
| **Container::make** | **1.67M ops/sec** | ~500K ops/sec | ~300K ops/sec | ❌ No container | ⚠️ C-level |

---

## 8. Features

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **Router** | ✅ Static O(1) + Dynamic | ✅ | ✅ | ✅ | ✅ |
| **QueryBuilder** | ✅ MySQL/PgSQL/SQLite | ✅ | ✅ | ❌ | ✅ |
| **ORM** | ✅ HasOne/HasMany/BelongsTo/BelongsToMany | ✅ Eloquent | ✅ Doctrine | ❌ | ✅ |
| **Migrations** | ✅ | ✅ | ✅ | ❌ | ✅ |
| **Cache** | ✅ File + Redis | ✅ File + Redis | ✅ File + Redis | ❌ | ✅ |
| **Queue** | ✅ DB-based, daemon, retry | ✅ Redis/SQS + Horizon | ✅ Messenger | ❌ | ❌ |
| **Mail** | ✅ SMTP + Sendmail | ✅ | ✅ | ❌ | ❌ |
| **Event System** | ✅ Pub/sub, wildcards | ✅ | ✅ | ❌ | ❌ |
| **File Storage** | ✅ Local + S3 | ✅ Local + S3/Cloud | ✅ Local + S3 | ❌ | ❌ |
| **OpenAPI/Swagger** | ✅ Auto-generate + Swagger UI | 🔌 | 🔌 | ❌ | ❌ |
| **SDK Generation** | ✅ Auto from OpenAPI | ❌ | ❌ | ❌ | ❌ |
| **Validation** | ✅ 15+ rules, custom | ✅ | ✅ | ✅ | ✅ |
| **Encryption** | ✅ AES-256-CBC + HMAC | ✅ | ✅ | ❌ | ❌ |
| **RBAC** | ✅ Built-in | 🔌 Gates | 🔌 Voters | ❌ | 🔌 |
| **Cursor Pagination** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Rate Limiting** | ✅ Per-route, Redis + File | ✅ | 🔌 | ❌ | ❌ |
| **API Versioning** | ✅ Header-based | ❌ | ❌ | ❌ | ❌ |

---

## 9. CI/CD & DevOps

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **CI Workflows** | **9** (Psalm, Fuzz, Chaos, CodeQL, SAST, DAST, Contract, Dependabot, Release) | ~3 | ~3 | ~1 | ~1 |
| **Dependency Review** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Dependabot** | ✅ | ✅ | ✅ | ❌ | ❌ |
| **Benchmark Regression CI** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Fuzz Testing CI** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Contract Testing** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Docker** | ✅ FrankenPHP + Dockerfile | ✅ Sail | ✅ Dockerfile | ✅ | ❌ |
| **Preloading** | ✅ preload.php | 🔌 | ✅ | ❌ | ❌ |

---

## 10. Developer Experience

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **CRUD Generator** | ✅ 1 command | 🔌 3rd party | 🔌 MakerBundle | ❌ | ❌ |
| **Auth Scaffolding** | ✅ `make:auth` | ✅ | 🔌 | ❌ | ❌ |
| **API Testing CLI** | ✅ `php siro t GET /api/users` | ❌ | ❌ | ❌ | ❌ |
| **Request Replay** | ✅ `php siro replay` | ❌ | ❌ | ❌ | ❌ |
| **Typo-tolerant CLI** | ✅ Levenshtein | ❌ | ❌ | ❌ | ❌ |
| **REPL / Tinker** | ❌ | ✅ | ❌ | ❌ | ❌ |
| **Hot Reload** | ❌ | ✅ | ❌ | ❌ | ❌ |
| **IDE Helpers** | ❌ | 🔌 | ✅ | ❌ | ❌ |

---

## 11. Ecosystem

| Tiêu chí | Siro | Laravel | Symfony | Slim | Phalcon |
|----------|:----:|:-------:|:-------:|:----:|:-------:|
| **Packagist packages** | 2 (core + skeleton) | 50,000+ | 30,000+ | 500+ | 100+ |
| **Community size** | Small | Largest in PHP | Large | Medium | Small |
| **Official documentation** | 14 docs (Markdown, hand-written) | Extensive | Extensive | Good | Good |
| **Video tutorials** | ❌ | Abundant | Many | Few | Few |
| **Books** | ❌ | Many | Several | ❌ | ❌ |
| **Forums / Chat** | ❌ | Discord, Laracasts | Slack | GH Discussions | Forum |
| **Conference talks** | ❌ | Dozens/year | Dozens/year | Few | Few |

---

## 12. Khi nào nên chọn?

### Chọn Siro khi

- Bạn cần **API framework** thuần tuý, không muốn HTTP overhead
- **Debugging và observability** là ưu tiên hàng đầu
- Bạn muốn **0 dependencies** → không CVE từ transitive deps
- Bạn cần **security hardened by default** (OWASP, JWT, CSRF, PII)
- Bạn muốn **testing infrastructure** mạnh: fuzz, mutation, chaos
- **Performance** critical (1ms boot, 488K routes/sec)
- Dự án **microservices** hoặc **serverless** (Lambda cold start)

### Chọn Laravel khi

- Bạn cần **ecosystem lớn nhất** (packages, tutorials, community)
- Bạn muốn **all-in-one** (ORM, queue, mail, notification, broadcasting)
- **Rapid application development** với nhiều built-in tools
- **Cộng đồng lớn** → dễ tìm nhân sự, support
- Bạn cần **admin panel** (Nova), **social auth** (Socialite), **scout** (search)

### Chọn Symfony khi

- Bạn xây dựng **enterprise application** phức tạp
- Bạn cần **architecture flexibility** (có thể dùng từng component riêng)
- **Long-term maintainability** với strict coding standards
- Bạn cần **Doctrine ORM** (mạnh nhất PHP)
- **Internationalization** phức tạp

### Chọn Slim khi

- Bạn cần **micro-framework đơn giản nhất**
- **Học trong 10 phút**, không cần cấu hình
- Kết hợp với các thư viện riêng lẻ

### Chọn Phalcon khi

- Bạn cần **peak performance** (C extension)
- Không ngại phụ thuộc vào extension đặc thù
- Bạn đã có kinh nghiệm với Phalcon

---

## Tổng kết

| Khía cạnh | Dẫn đầu | Ghi chú |
|-----------|---------|---------|
| **Performance** | Siro / Phalcon | Siro 0 deps, Phalcon C ext |
| **Security built-in** | **Siro** | OWASP, JWT, PII, SAST/DAST, SBOM, STRIDE |
| **Testing infrastructure** | **Siro** | Fuzz 17k, mutation, chaos — unique |
| **Debugging & replay** | **Siro** | Unique trong PHP ecosystem |
| **Static analysis** | **Siro** | PHPStan Max + Psalm L1 — 0 errors |
| **Ecosystem** | Laravel | Dẫn đầu tuyệt đối |
| **Enterprise architecture** | Symfony | Bundle system, flexibility |
| **Simplicity** | Slim | Micro-framework đơn giản nhất |
| **CI/CD quality gates** | **Siro** | 9 workflows, nhiều nhất |

Siro không cố cạnh tranh về ecosystem hay số lượng features.  
Siro tập trung vào: **debugging, security, testing, và observability** — những thứ mà các framework khác bỏ ngỏ.
