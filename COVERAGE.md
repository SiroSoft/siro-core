# Code Coverage Baseline — siro-core

Snapshot lấy từ `php -d zend_extension=xdebug vendor/bin/phpunit --coverage-text` tại v0.35.1 (2026-08-01).

## Tổng quan

| Metric | Giá trị |
|--------|---------|
| **Lines** | **18.57%** (3524 / 18978) |
| **Methods** | 25.22% (438 / 1737) |
| **Classes** | 1.57% (3 / 191) |

> Ghi chú: nhiều class được test **gián tiếp** qua feature/integration tests, nhưng
> coverage theo line chỉ tính khi class được nạp + chạy trực tiếp trong test suite
> đơn vị. Vì vậy con số này là cận dưới — con số thật có thể cao hơn.

## Vì sao v1.0 cần cải thiện

- **Model (32% lines), Queue (3.7%), Storage (11%), Response (24%), Schema (25%)**
  là các class lõi — nếu ít test trực tiếp, nguy cơ regression khi đổi code cao.
- v1.0 cam kết API stability → cần test trực tiếp cho các class public quan trọng.

## Ưu tiên test thêm (theo giá trị)

| Ưu tiên | Class | Lines hiện tại | Vì sao |
|---------|-------|----------------|--------|
| 1 | `Queue` | 3.78% | Lõi background jobs, nhiều method public |
| 2 | `Storage` | 11.31% | Filesystem abstraction, dễ test |
| 3 | `Response` | 24.60% | Format response, nhiều branch |
| 4 | `Schema` | 25.61% | Migration builder, ít test hiện có |
| 5 | `Model` | 32.43% | ORM lõi, 61 method — khó nhất |
| 6 | `Session` | 57.53% | Đã khá, thêm phần flash |

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

- [ ] `Queue` ≥ 50% lines
- [ ] `Storage` ≥ 50% lines
- [ ] `Response` ≥ 50% lines
- [ ] `Model` ≥ 40% lines
- [ ] Tổng lines ≥ 30% (từ 18.57%)
