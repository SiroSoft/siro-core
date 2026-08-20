# Release Process — Siro (siro-core + SiroPHP)

Quy trình chuẩn để release một version mới (patch/minor/major). Lặp lại cho
MỌI lần upgrade. Đọc kỹ trước khi bắt đầu.

> Bản gốc: v0.35.1 → v1.0.0
> Cập nhật lần cuối: 2026-08-01

---

## 0. Nguyên tắc

- **siro-core** = thư viện (version ở tag, Packagist tự lấy)
- **SiroPHP** = project skeleton (nhánh release riêng)
- Luôn làm trên nhánh release, KHÔNG commit thẳng main
- Chạy checklist đầy đủ trước khi tag

---

## 1. Chuẩn bị code (trước release)

```bash
# Trong siro-core
composer validate --no-check-publish          # phải ra "./composer.json is valid"
composer analyse                               # PHPStan level=max, 0 lỗi
composer test                                  # PHPUnit, 0 fail/notice/deprecation

# Kiểm tra nhanh
php vendor/bin/phpstan analyse --level=max --no-progress
php vendor/bin/phpunit --no-coverage
```

**Bắt buộc pass trước khi tiếp tục:**
- [ ] composer validate: valid
- [ ] PHPStan: 0 lỗi
- [ ] PHPUnit: 2870+ tests, 0 failures

---

## 2. Cập nhật docs theo version mới

| File | Việc cần làm |
|---|---|
| `siro-core/CHANGELOG.md` | Thêm section `## vX.Y.Z (date)` ở đầu, liệt kê đủ thay đổi |
| `siro-core/RELEASE_NOTES.md` | Thêm section vX.Y.Z (highlight tính năng) |
| `siro-core/KNOWN_ISSUES.md` | Thêm "Resolved since" nếu có fix + "Current limitations" |
| `siro-core/UPGRADE.md` | Thêm section `vX → vX+1` nếu có breaking change |
| `siro-core/README.md` | Cập nhật badge test count (nếu số test đổi) |
| `siro-core/SECURITY.md` | Cập nhật bảng supported versions |
| `siro-core/RELEASE_CHECKLIST.md` | Đánh dấu lại các mục đã xong |

**Lưu ý**: dùng PHP script để ghi file (tránh BOM/UTF-16 từ PowerShell).

---

## 3. Kiểm tra CI trước khi tag

- [ ] Push nhánh release lên GitHub
- [ ] CI chạy **matrix ubuntu/macos/windows × PHP 8.2/8.3/8.4** phải XANH cả 3 OS
- [ ] Kiểm tra: phpunit, phpstan, lint, mutation (nếu có), benchmark, technical-gate

---

## 4. Tag + Push

```bash
# siro-core
git -C siro-core checkout release/vX.Y.Z   # hoặc tạo mới từ main
git -C siro-core add -A
git -C siro-core commit -m "chore: vX.Y.Z release prep"
git -C siro-core push origin release/vX.Y.Z
git -C siro-core tag -a vX.Y.Z -m "vX.Y.Z — <mô tả ngắn>"
git -C siro-core push origin vX.Y.Z

# SiroPHP (project skeleton)
git -C SiroPHP checkout -b release/vX.Y.Z
git -C SiroPHP add -A
git -C SiroPHP commit -m "chore: vX.Y.Z release prep"
git -C SiroPHP push origin release/vX.Y.Z
```

> Nếu có merge từ nhánh release cũ: `git merge origin/release/vX.(Y-1).Z` và giải conflict.

---

## 5. Soak test (production pilot) 🔁

```bash
# Khởi động server SiroPHP
php SiroPHP/siro serve --port=8143

# Chạy soak 100 request kiểm tra nhanh
php siro-core/scripts/soak-test.php --base=http://localhost:8143 --requests=100 --concurrency=10 --verify-traces --traces-dir=SiroPHP/storage/logs/traces

# Soak 48h cho major release
# (chạy liên tục, giám sát memory/errors)
```

**Tiêu chí pass:** 0 errors, traces tăng đúng số request.

---

## 6. Merge + Publish

```bash
# SiroPHP: merge release/vX.Y.Z vào main (qua PR, main có branch protection)
# siro-core: push tag → GitHub Actions release.yml tự tạo GitHub Release

# Packagist (sirosoft/core) tự nhận tag mới từ webhook
```

---

## 7. Sau release (verification)

- [ ] `composer show sirosoft/core` ra version mới
- [ ] `composer create-project sirosoft/api test-app` chạy được
- [ ] GitHub Release note có đầy đủ changelog
- [ ] `siro new test-app` tạo project chạy được

---

## Checklist tóm tắt (in ra mỗi release)

```
[ ] composer validate valid
[ ] PHPStan 0 lỗi
[ ] PHPUnit clean (2870+ tests)
[ ] CHANGELOG/RELEASE_NOTES/KNOWN_ISSUES/UPGRADE cập nhật
[ ] README badge + SECURITY versions cập nhật
[ ] CI xanh 3 OS (ubuntu/macos/windows)
[ ] Soak 48h không lỗi (major)
[ ] Tag vX.Y.Z (siro-core + SiroPHP)
[ ] Push tag + merge release vào main
[ ] Packagist + create-project verify
```

---

## Ghi chú lịch sử thực thi
| Version | Ngày | Ghi chú |
|---|---|---|
| v0.35.1 | 2026-08-01 | Lần đầu áp dụng quy trình này |
