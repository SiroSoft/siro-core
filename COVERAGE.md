# Code Coverage Baseline — siro-core

Snapshot lấy từ `php -d zend_extension=xdebug vendor/bin/phpunit --coverage-text` tại v0.35.1 (2026-08-01).

## Tổng quan

| Metric | Giá trị |
|--------|---------|
| **Lines** | **19.83%** (3764 / 18978) |
| **Methods** | 26.37% (458 / 1737) |
| **Classes** | 1.57% (3 / 191) |

> Ghi chú: nhiều class được test **gián tiếp** qua feature/integration tests, nhưng
> coverage theo line chỉ tính khi class được nạp + chạy trực tiếp trong test suite
> đơn vị. Vì vậy con số này là cận dưới — con số thật có thể cao hơn.

## Tiến độ cải thiện (2026-08-01)

| Class | Trước | Sau | Test mới |
|-------|-------|-----|----------|
| `Response` | 21.77% lines | **47.98%** | ResponseApiTest (19 tests: problem/download/stream/file/headers/redirect/send) |
| `Queue` | 3.78% | **20.64%** | QueueDatabaseTest (9 tests: pending/failed counts, retry, flush) |
| `Storage` | 11.31% | **40.64%** | StorageDiskTest (13 tests: real-disk put/get/copy/size/files/path-traversal) |

## Ưu tiên test thêm (theo giá trị)

- **Model (32% lines), Queue (3.7%), Storage (11%), Response (24%), Schema (25%)**
  là các class lõi — nếu ít test trực tiếp, nguy cơ regression khi đổi code cao.
- v1.0 cam kết API stability → cần test trực tiếp cho các class public quan trọng.

## Ưu tiên test thêm (theo giá trị)

| Ưu tiên | Class | Lines hiện tại | Vì sao |
|---------|-------|----------------|--------|
| 1 | `Model` | 32.43% | ORM lõi, 61 method — khó nhất, còn thấp |
| 2 | `Schema` | 25.61% | Migration builder, ít test hiện có |
| 3 | `Session` | 57.53% | Đã khá, thêm phần flash |

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
- [x] `Response` ≥ 45% lines (đạt 47.98%)
- [ ] `Model` ≥ 40% lines (hiện 32.43%)
- [ ] `Schema` ≥ 40% lines (hiện 25.61%)
- [ ] Tổng lines ≥ 30% (hiện 19.83%)
