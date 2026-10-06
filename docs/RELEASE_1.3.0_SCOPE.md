# Release Scope: v1.3.0 — Attributes + Test DX

Theme: write less, break earlier, test faster. All additive, zero BC breaks.

## A. Controller attributes (`Siro\Core\Attributes\`, all English docs)

New repeatable PHP 8 attributes for controller classes/methods:

| Attribute | Target | Effect |
|---|---|---|
| `Body($key, $rules='', $type='string', $description='', $required=true)` | method ×N | OpenAPI requestBody field; doubles as validation source |
| `QueryParam($key, $type='string', $description='', $required=false)` | method ×N | OpenAPI query parameter |
| `Middleware(...$middleware)` | class + method | Appended at `registerAttributes()` discovery |
| `Authorize($ability)` | method (repeatable) | Enforced at dispatch via new `AuthorizeMiddleware` (Gate, `$request->user()`); 403 handled |
| `Throttle($max, $minutes=1)` | class + method | Desugars to `ThrottleMiddleware` entry |
| `CacheResponse($ttl)` | class + method | Sets route cache TTL |

- New `Middleware\AuthorizeMiddleware` (`handle($request, $next, string ...$abilities)`)
  so abilities stay serializable for route cache (no closures).
- `MakeOpenApiCommand`: attribute-declared Body/QueryParam win; existing
  `->input()` grep (Pattern 6) remains as fallback. No behavior change when
  no attributes are present.
- `RouteAttribute` untouched (BC).

## B. Test DX (`Siro\Core\Testing\`)

- `Faker`: zero-dependency seeded faker — `vnFullName()`, `vnPhone()`,
  `email()`, `uuid()`, `intBetween()`, `pick()`, `sentence()`, `date()`;
  `Faker::seed(int)` for determinism.
- `Factory`: abstract base (`$model`, `definition()`, `count()`, `with()`,
  `state()`, `create()`/`make()`). `make:factory` template updated to
  extend it with a Faker example.
- `TestClient` + `TestResponse`: fluent HTTP tests without a server —
  `TestClient::through($router)->actingAs($user)->postJson('/x', [...])
  ->assertOk()->assertJsonPath('data.id')`. Covers get/post/put/patch/delete,
  headers, `assertStatus/Ok/Created/NoContent/Forbidden/NotFound`,
  `assertJsonPath/Fragment/Count`.
- `Mail`/`Queue` already fakeable; no new API (documented in TESTING.md).

## Explicitly Not Included

- `make:test --from-trace`, `db:why`, pretty local logs, `siro release`
  command, migration diff, `#[Inject]` method-injection, policy classes
  (callables suffice), time-freezing (no core clock abstraction yet).
- No change to validation engine, auth flow, or response envelope.

## Acceptance Gates

- `composer release:check` equivalent: PHPStan level max 0 errors,
  full suite green (incl. 2 consecutive runs for flake check),
  `composer validate`, `composer audit`.
- New suites: `AttributesTest`, `AuthorizeMiddlewareTest`,
  `OpenApiAttributesTest`, `FakerTest`, `FactoryTest`, `TestClientTest`.
- `make:openapi` output on SiroPHP skeleton unchanged without attributes.
