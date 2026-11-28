# Code Coverage Baseline — siro-core

## Tổng quan (v1.5.2, 2026-11-28)

> **Chưa đo lại coverage ở v1.5.2 — các % dưới đây vẫn là baseline đo lần
> cuối ở v0.40.0 (2026-08-20), giữ nguyên, không bịa số mới.** Máy hiện tại
> (2026-11-28, PHP 8.4.28) không có coverage driver: `php8.4 -m` không có
> xdebug lẫn pcov, và full suite ~22.462 test chạy coverage sẽ rất lâu.
> Muốn đo lại: `apt-get install -y php8.4-xdebug` (hoặc pcov) rồi chạy
> `vendor/bin/phpunit --coverage-text`.
>
> Mốc thay thế đã verify ở v1.5.2: `scripts/release-check.php` exit 0 —
> **22.462 tests / 41.090 assertions, 0 failures** (28/11/2026; gồm
> 3.520 Unit + 654 Cli + Integration/Fuzz/Security/DAST), PHPStan level
> max 0 lỗi, boot min 0,43–0,44 ms.

| Metric | Giá trị |
|--------|---------|
| **Statements** | **80.00%** (14,294 / 17,867) — baseline v0.40.0, chưa đo lại |
| **Methods** | ~75% — baseline v0.40.0, chưa đo lại |
| **Classes** | ~70% — baseline v0.40.0, chưa đo lại |

> Coverage measured via `phpunit --coverage-clover` + Xdebug on cloud server (Ubuntu 24.04, PHP 8.3.6).

## Thay đổi từ baseline (v0.40.0 → v1.5.2)

Số test mới thêm từ sau baseline (lấy từ CHANGELOG, không phải số coverage):

| Nhóm | Test mới |
|------|----------|
| OAuth2 bridge (G2, v1.5.0) | 53 (28 unit + 16 security + 9 E2E) |
| Auto-CRUD + filter/sort (G3, v1.5.0) | 61 tests / 130 assertions |
| Signed webhooks (G5, v1.5.0) | 27 (16 unit + 11 integration) |
| CLI smoke fixes (v1.5.1) | 132 regression tests; `tests/cli/` (654 tests) nay chạy trong gate qua testsuite `Cli` mới trong `phpunit.xml` |
| Boot fix (v1.5.2) | 3 regression tests |
| O2/O3 fixes (v1.5.1) | unit + E2E + 1 regression test |
| `json` cast compatibility (v1.4.0) | 25 `ModelJsonCast` tests |
| **Full suite** | **21.504 tests (v1.4.0) → 22.462 tests (v1.5.2)** |

## Tiến độ cải thiện

> Bảng dưới là từ baseline v0.40.0, giữ nguyên để đối chiếu — chưa đo lại ở v1.5.2.

| Class | Trước | Sau | Test mới |
|-------|-------|-----|----------|
| `Response` | 21.77% lines | **56.05%** | ResponseApiTest |
| `Queue` | 3.78% | **20.64%** | QueueDatabaseTest |
| `Storage` | 11.31% | **40.64%** | StorageDiskTest |
| `Model` | 32.43% | **61.56%** | ModelDatabaseTest |
| `Schema` | 25.61% | **74.12%** | SchemaDatabaseTest |
| `Cache` | (chưa đo) | **78.95%** | CacheExtendedTest |
| `Mail` | (chưa đo) | **35.96%** | MailTest |
| `Session` | 57.53% | **69.35%** | SessionFlashTest |
| `QueryBuilder` | 17.84% | **32.76%** | QueryBuilderExecuteTest |
| `LogReplayCommand` | 2.33% | **19.25%** | LogReplayTest |
| `Blueprint` | 0% | **94.2%** | BlueprintMutationTest |
| `Column` | 0% | **100%** | ColumnMutationTest |
| `JoinClause` | 0% | **100%** | JoinClauseMutationTest |
| `ForeignKey` | 0% | **100%** | ForeignKeyMutationTest |

## Mutation Testing

> Số dưới là từ baseline v0.40.0, chưa đo lại ở v1.5.2.

| Scope | Mutants | Killed | Escaped | Not Covered | MSI |
|-------|:-------:|:------:|:-------:|:-----------:|:---:|
| Auth | 152 | 125 | 20 | 7 | **82%** |
| Middleware | 112 | 93 | 7 | 12 | **83%** |
| **Combined** | **264** | **218** | **27** | **19** | **~83%** |

> MSI target ≥80% achieved. Harmless mutators excluded (ArrayItemRemoval, Cast, Concat, LogicalOr/And, PregMatch anchors, Throw_, MethodCallRemoval, etc.).

## Cách chạy coverage

```bash
# Cài xdebug (Linux, PHP 8.4 — máy hiện tại chưa có driver, 2026-11-28)
sudo apt-get install -y php8.4-xdebug
# hoặc pcov: sudo apt-get install -y php8.4-pcov

# Cài xdebug (Windows)
# tải php_xdebug-3.3.x-8.2-vs16-nts-x86_64.dll vào ext/
# thêm vào php.ini: zend_extension=xdebug  +  xdebug.mode=coverage

# Chạy coverage
php -d zend_extension=xdebug vendor/bin/phpunit --coverage-text

# Báo cáo HTML
php -d zend_extension=xdebug vendor/bin/phpunit --coverage-html coverage/html

# Mutation testing
php vendor/bin/infection --min-msi=80 --threads=4
```

## Mục tiêu v1.0

> Đạt ở baseline v0.40.0; chưa đo lại ở v1.5.2.

- [x] `Queue` ≥ 20% lines (đạt 20.64%)
- [x] `Storage` ≥ 40% lines (đạt 40.64%)
- [x] `Response` ≥ 45% lines (đạt 56.05%)
- [x] `Model` ≥ 40% lines (đạt 61.56%)
- [x] `Schema` ≥ 40% lines (đạt 74.12%)
- [x] `Cache` ≥ 50% lines (đạt 78.95%)
- [x] `Session` ≥ 50% lines (đạt 69.35%)
- [x] `QueryBuilder` ≥ 50% lines (in progress)
- [x] Tổng statements ≥ 80% (đạt 80.00%)
