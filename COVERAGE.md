# Code Coverage Baseline — siro-core

Snapshot lấy từ `php -d zend_extension=xdebug vendor/bin/phpunit --coverage-text` tại v0.35.1 (2026-08-01).

## Tổng quan

| Metric | Giá trị |
|--------|---------|
| **Lines** | **21.75%** (4131 / 18989) |
| **Methods** | 29.48% (512 / 1737) |
| **Classes** | 1.57% (3 / 191) |

> Ghi chú: nhiều class được test **gián tiếp** qua feature/integration tests, nhưng
> coverage theo line chỉ tính khi class được nạp + chạy trực tiếp trong test suite
> đơn vị. Vì vậy con số này là cận dưới — con số thật có thể cao hơn.

## Tiến độ cải thiện (2026-08-01)

| Class | Trước | Sau | Test mới |
|-------|-------|-----|----------|
| `Response` | 21.77% lines | **56.05%** | ResponseApiTest |
| `Queue` | 3.78% | **20.64%** | QueueDatabaseTest |
| `Storage` | 11.31% | **40.64%** | StorageDiskTest |
| `Model` | 32.43% | **61.56%** | ModelDatabaseTest |
| `Schema` | 25.61% | **74.12%** | SchemaDatabaseTest |
| `Cache` | (chưa đo) | **78.95%** | CacheExtendedTest (remember/requestStatus/flush prefix) |
| `Mail` | (chưa đo) | **35.96%** | MailTest (chain, sanitize, queue, sendLater) |
| `Session` | 57.53% | **69.35%** | SessionFlashTest (flash lifecycle, persistence, destroy, gc) |
| `QueryBuilder` | 17.84% | **32.76%** | QueryBuilderExecuteTest (22 tests: CRUD, joins, aggregates, paginate) |

**Bug phát hiện khi viết test:**
- `Schema::hasTable` escape `_` làm hỏng so sánh `=` trên sqlite/pgsql (đã fix)
- `QueryBuilder::distinct()` SQL sai — `SELECT DISTINCT, \`col\`` thiếu từ khóa DISTINCT đúng chỗ; `select()` sau `distinct()` xóa marker (đã fix)
- Fix test-isolation EncrypterTest (đồng bộ `$_ENV['APP_KEY']` + putenv) — hết flake

## Ưu tiên test thêm (theo giá trị)

- **Model (32% lines), Queue (3.7%), Storage (11%), Response (24%), Schema (25%)**
  là các class lõi — nếu ít test trực tiếp, nguy cơ regression khi đổi code cao.
- v1.0 cam kết API stability → cần test trực tiếp cho các class public quan trọng.

## Ưu tiên test thêm (theo giá trị)

| Ưu tiên | Class | Lines hiện tại | Vì sao |
|---------|-------|----------------|--------|
| 1 | `ModelQueryBuilder` | 12.57% | Query builder cho Model — chạy được, ít test |
| 2 | `EagerLoader` | 1.48% | Eager loading relations |
| 3 | `LogReplayCommand` | 2.33% | Replay logic (857 lines) |

## Cách chạy coverage

```bash
# Cài xdebug (Windows)
# tải php_xdebug-3.3.x-8.2-vs16-nts-x86_64.dll vào ext/
# thêm vào php.ini: zend_extension=xdebug  +  xdebug.mode=coverage

# Chạy coverage
php -d zend_extension=xdebug vendor/bin/phpunit --coverage-text

# Báo cáo HTML
php -d zend_extension=xdebug vendor/bin/phpunit --coverage-html coverage/html
```

## Mục tiêu v1.0

- [x] `Queue` ≥ 20% lines (đạt 20.64%)
- [x] `Storage` ≥ 40% lines (đạt 40.64%)
- [x] `Response` ≥ 45% lines (đạt 56.05%)
- [x] `Model` ≥ 40% lines (đạt 61.56%)
- [x] `Schema` ≥ 40% lines (đạt 74.12%)
- [x] `Cache` ≥ 50% lines (đạt 78.95%)
- [x] `Session` ≥ 50% lines (đạt 69.35%)
- [ ] `QueryBuilder` ≥ 50% lines (hiện 32.76%)
- [ ] Tổng lines ≥ 30% (hiện 21.75%)
