---
title: OAuth2
description: SiroPHP OAuth2 reference (league/oauth2-server bridge)
sidebar_position: 7
sidebar_label: OAuth2
---

# OAuth2

Third-party partner authentication via OAuth 2.0, built on
[`league/oauth2-server`](https://oauth2.thephpleague.com/) 9.x (direction B of
the v1.5.0 plan). The library is **optional**: it is a `suggest` + `require-dev`
dependency, guarded by `class_exists`, so an app that never enables OAuth2
ships zero extra vendor code and boots unchanged.

> **ApiKey vs OAuth2.** Use `ApiKey` for trusted server-to-server calls where
> one static key with a fixed scope set is enough. Use OAuth2 when opening the
> API to third-party partners: standard grants, per-client scopes, token
> expiry and revocation. The two mechanisms coexist — existing ApiKey code is
> unchanged. See `Auth/ApiKey.php`.

---

## Grants

| Grant | Flow | Refresh token |
|-------|------|---------------|
| `authorization_code` (+PKCE, S256 required for public clients) | `GET /oauth/authorize` → code → `POST /oauth/token` | yes |
| `client_credentials` | `POST /oauth/token` | no |
| `refresh_token` | `POST /oauth/token` (rotates: the old refresh token is revoked) | yes (new one) |

Access tokens are League JWTs (RS256, signed with the OAuth private key,
`scopes` array claim, TTL 3600s by default). Core `JWT` tokens keep working
unchanged; `OAuthScopeMiddleware` accepts both (core JWT `scope` string claim
or League `scopes` array claim).

## Setup

```bash
# 1. Install the optional libraries (dev installs them; production does not)
composer require league/oauth2-server laminas/laminas-diactoros

# 2. Generate keys (RSA 2048 + defuse encryption key, written to .env)
php siro make:oauth-keys

# 3. Create a client (the secret is shown ONCE)
php siro make:oauth-client "Acme Partner" \
    --redirect-uri=https://partner.example/callback \
    --grants=authorization_code,client_credentials,refresh_token \
    --scopes=read
```

Tables (`oauth_clients`, `oauth_access_tokens`, `oauth_refresh_tokens`,
`oauth_auth_codes`) are created by `AuthorizationServer::createTables()` —
one table per repository, same pattern as `ApiKey` / `Idempotency`. There is no
separate migration: run the command once, or call `createTables()` from app
bootstrap.

## Configuration (`config/oauth.php`)

```php
return [
    'scopes' => ['read', 'write'],   // allowlist; unknown scopes are rejected
    'default_scope' => 'read',
    'access_token_ttl' => 3600,      // seconds
    'refresh_token_ttl' => 2592000,  // 30 days
    'auth_code_ttl' => 600,          // 10 minutes
];
```

Environment overrides: `OAUTH_PRIVATE_KEY_PATH`, `OAUTH_PUBLIC_KEY_PATH`,
`OAUTH_ENCRYPTION_KEY` (plus `OAUTH_PRIVATE_KEY` / `OAUTH_PUBLIC_KEY` inline
values). Scopes may also be set per client (`scopes` column, comma-separated);
a token request is narrowed to `requested ∩ client ∩ config`.

## Routes (app bootstrap)

```php
use Siro\Core\Auth\OAuth2\AuthorizationServer;
use Siro\Core\Router;

Router::registerMiddlewareAlias('oauth', \Siro\Core\Middleware\OAuthScopeMiddleware::class);

$router->post('/oauth/token', fn (Request $r) => AuthorizationServer::handleTokenRequest($r));
$router->post('/oauth/revoke', fn (Request $r) => AuthorizationServer::handleRevokeRequest($r));
$router->get('/oauth/authorize', function (Request $request) {
    try {
        // Your own approval screen here; then:
        $authRequest = AuthorizationServer::validateAuthorizationRequest($request);
        return AuthorizationServer::completeAuthorizationRequest($authRequest, $userId, $approved);
    } catch (\League\OAuth2\Server\Exception\OAuthServerException $e) {
        // Wrong redirect_uri, unknown client, bad scope, ... — answer with
        // the proper OAuth error (401 invalid_client etc.) instead of a 500.
        // Never redirect to the requested URI here: it is unregistered
        // (RFC 6749 §4.1.2.1), and the helper answers directly.
        return AuthorizationServer::authorizationErrorResponse($e);
    }
});

$router->get('/api/partner/data', [PartnerController::class, 'data'], ['oauth:read']);
$router->post('/api/partner/orders', [PartnerController::class, 'create'], ['oauth:read,write']);
```

Do **not** register the `oauth` alias in `App::boot()` — that file belongs to
another lane; the app registers it in its own bootstrap.

## Token endpoint

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=...&client_secret=...&scope=read
```

Success (`200`):

```json
{"token_type": "Bearer", "expires_in": 3600, "access_token": "eyJ..."}
```

Errors follow RFC 6749 §5.2 (`invalid_client` is returned with **401** by the
League library when client authentication fails, `invalid_grant` /
`invalid_scope` / `invalid_request` with 400).

## Revocation (RFC 7009)

```
POST /oauth/revoke
token=<access-or-refresh-token>&token_type_hint=access_token
&client_id=...&client_secret=...
```

Always returns `200` — even for unknown tokens, and even when the token
belongs to another client (in that case it is **not** revoked). A revoked
access token is rejected on next use (`401 invalid_token`); a revoked refresh
token cannot be exchanged (`400 invalid_grant`).

## Scope enforcement

`OAuthScopeMiddleware::handle($request, $next, ...$scopes)`:

- missing / expired / revoked / tampered token → `401 invalid_token`
  (+ `WWW-Authenticate: Bearer realm="api", error="invalid_token"`)
- token without a required scope → `403 insufficient_scope`
  (+ `WWW-Authenticate: Bearer realm="api", error="insufficient_scope", scope="..."`)
- success → `$request->user()` is
  `['id' => ?, 'type' => 'oauth2'|'oauth2_client', 'client_id' => ?, 'scopes' => [...], 'token_id' => ?]`

`AuthGuard` also understands OAuth2: when the bridge is enabled, an OAuth2
Bearer token is validated first; a core JWT falls through to the existing
flow unchanged. When OAuth2 is disabled, the guard behaves byte-identically
to before.

## Security notes

- Client secrets are bcrypt-hashed; the plaintext is shown once at creation.
- Auth codes are single-use; PKCE (`S256`) is required for public clients.
- Refresh tokens rotate on use; reuse of a rotated token fails.
- Revocation is fail-closed: an unknown access-token id is treated as revoked.
- Cross-client revocation is refused (a client can only revoke its own tokens).
- Scope is never escalated: refresh and token requests are narrowed to the
  intersection of requested, client-allowed and configured scopes.

## Testing

- `tests/unit/AuthOAuth2Test.php` (28 tests) — clients, scopes, grants
  in-process, middleware, commands.
- `tests/security/OAuth2PenTest.php` (16 tests) — forged / tampered / expired /
  `alg:none` tokens, PKCE abuse, code reuse, cross-client revocation, SQL
  injection in `scope`.
- `tests/integration/OAuth2E2eTest.php` (9 tests) — all three grants over real
  HTTP (`php -S` + fixture `tests/fixtures/oauth2-app`).
- Quirk: under `php -S`, PHP's cli-server SAPI forces the status to **401**
  whenever a `WWW-Authenticate` header is present, even when the app set 403
  (verified with a hardcoded 403 + header debug route). Production SAPIs
  (FPM/RoadRunner) preserve 403. The E2E scope test therefore asserts the
  contract (body `error_code` + header) and accepts 401 or 403; the true 403
  is asserted in-process by the unit test.
