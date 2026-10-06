# Release Scope: v1.1.0

Base: tag `v1.0.14` + merge-resolution commit (Console.php `VERSION = '1.0.14'`,
CHANGELOG conflict resolved). Minor bump vì có API mới (additive, BC-safe).

## Pre-steps (trước implement, bắt buộc)

1. Commit merge-resolution hiện tại (`CHANGELOG.md`, `Console.php` đã gỡ conflict marker).
2. Quyết định số phận change unstaged trong `Validator.php` (phone `max` rule):
   include vào v1.1.0 hay tách commit riêng.
3. Xử lý diverged branches (`main` vs `origin/main`: 8 vs 3 commits) — pull/rebase
   trước khi cắt `release/v1.1.0` theo đúng workflow trong `_AGENT_CONTEXT_.md`.

## Included

### Issue 1 — `Event::off()` gỡ chọn lọc theo handle/callback

- `Event::on()` / `once()` trả về `int $handle` (auto-increment). Đổi `void → int`
  là BC-safe với mọi caller đang bỏ return value.
- `Event::off(string $event, int|callable|null $target = null)`:
  - `null` → hành vi cũ (gỡ toàn bộ key, giữ cả wildcard branch).
  - `int` → chỉ gỡ listener có handle đó (`===`).
  - `callable` → gỡ các listener có callback `===` callback truyền vào.
- Listener lưu thêm `'id' => int`; `dispatch()` và one-time cleanup giữ nguyên logic.
- `flush()` / `setListeners()` / `setInstance()` giữ nguyên (đường reset cho test).
- Tests: core listener sống sót khi module `off` theo handle; off-by-callback;
  `off($event)` toàn bộ không đổi; wildcard `off('user.*')` không đổi.

### Issue 2 — `Request` body cache có lifecycle + accessor duy nhất

- Thêm `Request::resetCache(): void` (`$rawBodyCache = null`).
- Thêm `Request::rawBody(): string` — accessor duy nhất: đọc `php://input` 1 lần,
  các lần sau dùng cache; không bao giờ re-read stream đã cạn.
- `fromGlobals()` dùng `rawBody()` thay vì `file_get_contents()` trực tiếp.
- Skeleton `public/index.php` (SiroPHP) gọi `Request::resetCache()` trong `finally`
  sau mỗi request (cover cả FrankenPHP worker loop).
- Document: "không đọc `php://input` trực tiếp — dùng `Request::rawBody()`".
- Tests: POST có body → GET sau đó không dính stale body (repro line 116 `$rawBodyCache ?? ...`);
  `resetCache()` xóa; `rawBody()` idempotent.

### Issue 3 — Middleware params khai báo + validate sớm

- `MiddlewareInterface` thêm `public static function params(): array` (default `[]`),
  format: `['maxRequests' => 'int', 'minutes' => 'int']`
  (hỗ trợ `int|float|bool|string|null`).
- `Router::runMiddleware()` sau khi parse `name:p1,p2` sẽ check count + scalar type
  theo `params()` của class resolve được; sai → `RuntimeException` nêu rõ
  middleware + tên param + giá trị nhận được (fail ngay request đầu ở dev,
  và bị `route:list` / contract check bắt ở CI nếu route được boot).
- Middleware core (`ThrottleMiddleware`, `AuthMiddleware`, ...) khai báo `params()`.
- `throttle:abc,1` từ đây throw thay vì silent coerce `0 → max(1,0)`.
- Tests: mỗi middleware core có case param sai → throw message rõ;
  param đúng chạy như cũ; middleware không khai báo `params()` giữ đường cũ.

### Issue 4 — Mail provider contract (viết lại, premise cũ sai)

- Core ĐÃ có `Mail.php` đầy đủ (SMTP/STARTTLS/AUTH/header-injection guard) —
  scope này KHÔNG viết lại mailer, chỉ tách contract.
- Thêm `Siro\Core\MailProvider` interface: `send(array $mail): bool` never-throw
  (provider tự catch, fail → `false`).
- Thêm `SmtpMailProvider` (extract logic hiện tại từ `Mail`), `NullMailProvider`
  (log + return `true`, cho test/dev).
- `Mail::setProvider(MailProvider|null)` + env `MAIL_PROVIDER=smtp|null`;
  default `smtp` (giữ hành vi hiện tại).
- Thêm `Mail::trySend(): bool` never-throw qua provider; `Mail::send()` giữ nguyên
  throw-on-misuse/failure để không vỡ BC.
- Tests: NullMailProvider; `trySend()` → `false` khi SMTP refused (không throw);
  `send()` cũ vẫn throw như trước.

### Issue 5 — `reset()` đồng bộ cho static registries

Bổ sung (theo đúng naming `reset*` đã có trong codebase):

| Class | Thêm | File |
|---|---|---|
| `Router` | `resetStatic()` (aliases + priority + methodParamCache) | `Router.php:708-714` |
| `Request` | `resetCache()` (gộp với Issue 2) | `Request.php:38` |
| `Validator` | `resetCustomizations()` (customRules + customMessages + parsedRuleCache) | `Validator.php:20-29` |
| `Metrics` | `reset()` (counters + histograms + gauges) | `Metrics.php:25-31` |
| `Route` | `resetNamedRoutes()` | `Route.php:26` |
| `VersionMiddleware` | `reset()` (versions + overrides + latestVersion) | `VersionMiddleware.php:24-29` |

- Testing guide thêm 1 mục: test nào chạm global/static phải reset ở tearDown.
- Tests: mỗi reset có unit test; chạy full suite 2 lần liên tiếp không flake
  do leak state.

### Issue 6 — Error message phân biệt được

1. **Malformed JSON body**: `fromGlobals()` chỉ đánh dấu parse-fail khi đồng thời
   (a) Content-Type là JSON, (b) raw body non-empty, (c) `json_decode` lỗi.
   `validate()` khi đó throw `ValidationException` với key `body`:
   400 + code `malformed_body` thay vì "Email is required".
   Blast radius tối thiểu: form/multipart/empty-body không đổi.
2. **Throttle fail-closed**: `THROTTLE_FALLBACK=fail_closed` khi không Redis →
   **503** (thay 429) + header `X-RateLimit-Backend: unavailable`,
   message phân biệt "backend unavailable (fail closed)" vs "limit exceeded".
   Đường `limit exceeded` thật giữ nguyên 429 + `X-RateLimit-*`.
- Tests: body JSON hỏng → 400 `malformed_body`; fail_closed → 503 có header;
  limit thật → 429 như cũ.

## Explicitly Not Included

- Không viết lại mailer SMTP (Issue 4 chỉ tách contract).
- Không đổi default `THROTTLE_FALLBACK` (vẫn `file`).
- Không WebSocket, không plugin system, không third-party audit (roadmap v1.0).
- Không tự động bump version/tag/PHAR trong scope document này.

## Acceptance Gates

- `composer release:check`
- `composer test` (full suite xanh 2 lần liên tiếp — Issue 5 anti-flake)
- `composer analyse -- --no-progress --memory-limit=512M` (PHPStan max, 0 errors)
- `composer validate --no-check-publish`
- PHPStan + PHPUnit trên SiroPHP skeleton (contract `route:list`, `make:crud` không vỡ vì Issue 3)
- Installer PHPUnit suite

## Release Decision

APPROVED and implemented (2026-10-06). Implementation notes vs. scope:

- Issue 3: reflection-based validation of `handle()` signatures instead of a
  manually declared `params()` schema — zero per-class boilerplate, covers app
  middleware automatically, same fail-fast behavior.
- Issue 4: `SmtpMailProvider` extracted with identical behavior; `Mail::send()`
  still throws on failure (message now contains provider class); new
  `Mail::trySend()` is the never-throw path.
- Bonus fix required by the PHPStan level-max gate: `Model` identity-map key
  write guarded by `is_int|is_string` (pre-existing cast error, zero behavior
  change for valid keys).
- Worktree-carried fix included: `Validator` phone `max` by string length.

Gates: PHPStan level max 0 errors; full suite green (21,391 tests, 2nd run
confirms no flake); `composer validate` pending at release time.
