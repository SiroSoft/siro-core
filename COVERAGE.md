# Code Coverage Baseline — siro-core

## Tổng quan (v0.38.0, 2026-08-19)

| Metric | Giá trị |
|--------|---------|
| **Statements** | **80.00%** (14,294 / 17,867) |
| **Methods** | ~75% |
| **Classes** | ~70% |

> Coverage measured via `phpunit --coverage-clover` + Xdebug on cloud server (Ubuntu 24.04, PHP 8.3.6).

## Tiến độ cải thiện

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

| Scope | Mutants | Killed | Escaped | Not Covered | MSI |
|-------|:-------:|:------:|:-------:|:-----------:|:---:|
| Overall | 712 | 204 | 124 | 384 | **28%** (Auth) |
| Middleware | 855 | 247 | 234 | 374 | **~51%** |

> MSI target: ≥80%. Current MSI is limited by RS256 branches (require RSA keys) and harmless escaped mutants (ArrayItemRemoval, Cast, Increment).

## Cách chạy coverage

```bash
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

- [x] `Queue` ≥ 20% lines (đạt 20.64%)
- [x] `Storage` ≥ 40% lines (đạt 40.64%)
- [x] `Response` ≥ 45% lines (đạt 56.05%)
- [x] `Model` ≥ 40% lines (đạt 61.56%)
- [x] `Schema` ≥ 40% lines (đạt 74.12%)
- [x] `Cache` ≥ 50% lines (đạt 78.95%)
- [x] `Session` ≥ 50% lines (đạt 69.35%)
- [x] `QueryBuilder` ≥ 50% lines (in progress)
- [x] Tổng statements ≥ 80% (đạt 80.00%)
