---
title: J WT
description: SiroPHP J WT reference
sidebar_position: 6
sidebar_label: J WT
---

# JWT

The JWT utility provides encode, decode, key rotation, per-token revocation via JTI blacklist, and audience validation. Supports HS256 and RS256 algorithms.

---

## Configuration

### Environment Variables

```env
JWT_SECRET=your-secret-key-min-32-chars
JWT_TTL=3600
JWT_ALGORITHM=HS256
JWT_PREVIOUS_SECRET=
JWT_KEY_VERSION=1

# For RS256 only:
JWT_PRIVATE_KEY_PATH=/path/to/private.pem
JWT_PUBLIC_KEY_PATH=/path/to/public.pem
```

| Variable | Default | Description |
|----------|---------|-------------|
| `JWT_SECRET` | — | HMAC secret (min 32 chars, rejected if placeholder-like) |
| `JWT_TTL` | `3600` | Access token TTL in seconds |
| `JWT_ALGORITHM` | `HS256` | Signing algorithm: `HS256` or `RS256` |
| `JWT_PREVIOUS_SECRET` | — | Previous secret for key rotation verification |
| `JWT_KEY_VERSION` | `1` | Current key version |
| `JWT_PRIVATE_KEY` | — | Inline private key (RS256) |
| `JWT_PRIVATE_KEY_PATH` | — | Path to private key file (RS256) |
| `JWT_PUBLIC_KEY` | — | Inline public key (RS256) |
| `JWT_PUBLIC_KEY_PATH` | — | Path to public key file (RS256) |

---

## Encode Tokens

### Access Token

```php
use Siro\Core\Auth\JWT;

$token = JWT::encodeAccess(
    userId: 42,
    tokenVersion: 1,
    ttl: 3600,        // optional, defaults to 3600
    audience: null     // optional audience claim
);
```

The access token payload includes:
- `sub` — user ID
- `ver` — token version (for invalidation on password change / logout)
- `iat` — issued at timestamp
- `exp` — expiration timestamp
- `type` — `"access"`
- `jti` — unique token ID (for revocation)
- `aud` — audience (optional)

### Refresh Token

```php
$token = JWT::encodeRefresh(
    userId: 42,
    tokenVersion: 1,
    ttl: 604800,           // optional, defaults to 604800 (7 days)
    jti: null,             // optional explicit JTI
    audience: null         // optional audience claim
);
```

Refresh token payload includes the same claims with `type: "refresh"`.

---

## Decode and Verify

```php
use Siro\Core\Auth\JWT;

try {
    $claims = JWT::decode($token);
    // $claims = ['sub' => 42, 'ver' => 1, 'exp' => ..., 'type' => 'access', ...]
    $userId = (int) $claims['sub'];
} catch (\RuntimeException $e) {
    // Invalid structure, bad signature, expired, revoked, or malformed
    echo $e->getMessage();
}
```

`decode()` performs:
1. Split into 3 segments (header, payload, signature)
2. Base64url-decode and JSON-parse header+payload
3. **Algorithm pinning** — always uses server-configured algorithm, ignores token's `alg` header
4. Signature verification (HS256 or RS256)
5. Expiration check
6. Issued-at sanity check (rejects `iat > now + 60s`)
7. `sub` (user ID) validation
8. `ver` (token version) validation
9. JTI blacklist check

---

## Algorithm Pinning

The server **always** enforces its own configured algorithm. The `alg` field in the token header is ignored during verification. This prevents algorithm confusion attacks (e.g. an attacker changing `alg` from `RS256` to `HS256` and signing with the public key).

```php
// Router.php (Router -> JWT)

// Always uses Env JWT_ALGORITHM, never trusts header['alg']
$alg = self::algorithm(); // reads JWT_ALGORITHM from env
```

---

## Key Rotation

### Graceful Key Rotation

```php
use Siro\Core\Auth\JWT;

// 1. Set new secret (old tokens remain valid via JWT_PREVIOUS_SECRET)
putenv('JWT_SECRET=new-secret-min-32-chars');
putenv('JWT_PREVIOUS_SECRET=old-secret-min-32-chars');

// 2. Rotate programmatically
JWT::rotateKey('newer-secret-min-32-chars');
// Increments JWT_KEY_VERSION, sets new JWT_SECRET

// 3. During verification, both current and previous secrets are tried
// Decode checks: hash_equals(sign(data, current_secret), signature) ||
//                hash_equals(sign(data, previous_secret), signature)
```

The `verifyHs256WithRotation()` method:
1. Verifies against the **current** `JWT_SECRET`
2. Falls back to `JWT_PREVIOUS_SECRET` if signature doesn't match
3. The previous secret is only accepted if `JWT_KEY_VERSION > 1`

---

## JTI Blacklist (v0.24)

Revoke individual tokens without changing the secret:

```php
use Siro\Core\Auth\JWT;

// Decode to get the JTI
$claims = JWT::decode($token);
$jti = $claims['jti'];
$exp = $claims['exp'];

// Blacklist this specific token
JWT::blacklistJti($jti, $exp);
// Stores in-memory + in cache until token expiry

// Subsequent decode attempts on this token will throw:
// "Token has been revoked."
```

The blacklist check runs inside `JWT::decode()` — any blacklisted JTI causes a `RuntimeException`.

---

## Audience Validation

```php
use Siro\Core\Auth\JWT;

$claims = JWT::decode($token);

// Validate audience (returns bool)
if (!JWT::validateAudience($claims, 'https://api.example.com')) {
    // Token was issued for a different service
    throw new RuntimeException('Invalid audience.');
}

// If token has no 'aud' claim, validateAudience() returns true (opt-in)
// If 'aud' is an array, it checks in_array($expected, $aud)
```

---

## AuthMiddleware Integration

The built-in `AuthMiddleware` decodes the JWT from the `Authorization: Bearer <token>` header and sets the authenticated user on the request:

```php
use Siro\Core\Route;
use Siro\Core\Middleware\AuthMiddleware;

// Protect routes with JWT auth
Route::get('/profile', [ProfileController::class, 'show'])
    ->middleware([AuthMiddleware::class]);

// Role-based authorization
Route::get('/admin/users', [AdminController::class, 'index'])
    ->middleware(['auth:admin']); // Requires role === 'admin'

Route::post('/posts', [PostController::class, 'store'])
    ->middleware(['auth:user,admin']); // Requires role 'user' or 'admin'
```

The middleware:
1. Extracts the Bearer token from the `Authorization` header
2. Calls `JWT::decode()` to verify the token
3. Looks up the user model by `sub` (user ID)
4. Validates `token_version` matches the user's stored version
5. Sets `$request->setUser([...])` with `id`, `name`, `email`, `role`, `status`, `claims`
6. Returns 401 on any failure (invalid token, expired, revoked, user not found)
7. Returns 403 if the required role does not match

---

## Error Handling

```php
use Siro\Core\Auth\JWT;

try {
    $claims = JWT::decode($token);
} catch (\RuntimeException $e) {
    // Handle specific error cases:
    match ($e->getMessage()) {
        'Invalid token structure.'        => // Malformed (not 3 segments)
        'Invalid token payload.'          => // Bad JSON in header/payload
        'Invalid token signature.'        => // Tampered or wrong secret
        'Token expired.'                  => // exp < now
        'Token issued in the future.'     => // iat > now + 60
        'JWT token missing required "sub" claim...' => // Missing user ID
        'JWT token missing required "ver" claim...' => // Missing version
        'Token has been revoked.'         => // JTI blacklisted
        default                           => // Unknown error
    };
}
```

---

## Security Considerations

### Timing Attacks

All signature comparisons use `hash_equals()` to prevent timing side-channel attacks:

```php
if (hash_equals(self::signHs256WithSecret($data, $currentSecret), $signature)) {
    return true;
}
```

### Algorithm Confusion Prevention

The server **pins** the algorithm at `JWT_ALGORITHM` env var. During `decode()`:

```php
// Always use server-configured algorithm, never trust the token's alg header
$alg = self::algorithm();
```

The token's `header.alg` is parsed but never used for verification.

### Weak Secret Detection

The `JWT_SECRET` is validated at runtime:

```php
// Rejects placeholder values like "change_this", "please_set", "your_secret"
// Requires minimum 32 characters
if ($looksLikePlaceholder || strlen($secret) < 32) {
    throw new RuntimeException('JWT_SECRET is too weak.');
}
```

---

## Full Example

```php
<?php

use Siro\Core\Auth\JWT;
use Siro\Core\Route;
use Siro\Core\Middleware\AuthMiddleware;

// Token issuance
Route::post('/auth/login', function (Request $req) {
    $user = User::where('email', $req->input('email'))->first();
    if (!$user || !password_verify($req->input('password'), $user->password)) {
        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    $accessToken = JWT::encodeAccess($user->id, $user->token_version);
    $refreshToken = JWT::encodeRefresh($user->id, $user->token_version);

    return [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => 3600,
    ];
})->throttle(5, 1);

// Token refresh
Route::post('/auth/refresh', function (Request $req) {
    $claims = JWT::decode($req->input('refresh_token'));

    if ($claims['type'] !== 'refresh') {
        return response()->json(['error' => 'Invalid token type'], 401);
    }

    $user = User::find($claims['sub']);
    if (!$user || $user->token_version !== (int) $claims['ver']) {
        return response()->json(['error' => 'Token revoked'], 401);
    }

    // Blacklist old refresh token
    JWT::blacklistJti($claims['jti'], $claims['exp']);

    // Issue new tokens
    return [
        'access_token' => JWT::encodeAccess($user->id, $user->token_version),
        'refresh_token' => JWT::encodeRefresh($user->id, $user->token_version),
    ];
});

// Logout — invalidate token version
Route::post('/auth/logout', function (Request $req) {
    $user = User::find($req->userId());
    $user->token_version += 1;
    $user->save();

    return ['message' => 'Logged out'];
})->middleware([AuthMiddleware::class]);
```
