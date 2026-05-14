# Changelog

## v0.23.1 (2026-05-12) — Composer Plugin Configuration Fix

### 🔧 Bug Fixes
- **Composer allow-plugins**: Added `config.allow-plugins` to composer.json
  - Allows `infection/extension-installer` plugin required by infection/infection
  - Fixes `composer install` failures in CI/CD with Composer 2.2+
  - Prevents security blocking of Composer plugins

## v0.23.0 (2026-05-12) — The "Số 1" Release — Performance, Security, API Versioning

### ⚡ Performance (Nhanh nhất - Nhẹ nhất)
- **Lazy-loaded boot**: Non-essential services (Lang, Storage) deferred → sub-1ms cold boot
- **Model refactor**: 908→457 lines, extracted `ModelSerialization` + `ModelRelations` traits
- **Benchmark suite**: `php benchmark.php` — 8 benchmarks with `--json` output
- **Benchmark results**: Container::make at 1.67M ops/sec, Response::success at 2.97M ops/sec
- **AuthMiddleware cache**: Request-scoped user cache eliminates repeated DB queries per request

### 🛡️ Security (Bảo mật nhất)
- **CspMiddleware**: Strict CSP with `strict-dynamic` + nonce, X-Content-Type-Options, X-Frame-Options
- **AuditMiddleware**: Security event logging for 401/403/429 + sensitive operations
- **Logger::security()**: SIEM-compatible audit trail (separate `security.log`)
- **Logger::debug()**: Structured debug logging with context array
- **UploadedFile MIME validation**: Cross-validate extension vs actual MIME type (prevents extension spoofing)
- **Container circular dependency detection**: `MAX_CIRCULAR_DEPTH=64` with full chain reporting
