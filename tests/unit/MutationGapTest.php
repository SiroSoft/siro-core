<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Auth\JWT;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Targeted mutation tests — kills escaped mutants in Auth/Cache.
 *
 * Each test exercises a boundary condition that Infection's mutators
 * can alter without the test catching it.
 */
final class MutationGapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('JWT_SECRET=test-secret-key-that-is-long-enough-for-32-chars');
        putenv('JWT_ALGORITHM=HS256');
        putenv('JWT_KEY_VERSION=1');
        putenv('APP_ENV=testing');
        // In-memory SQLite so ApiKey tests run everywhere (local + CI)
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        ApiKey::createTable();
    }

    protected function tearDown(): void
    {
        JWT::reset();
        Env::reset();
        parent::tearDown();
    }

    // ================================================================
    // JWT — decode validation boundaries
    // ================================================================

    public function testDecodeRejectsExpiredToken(): void
    {
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => time() - 7200, 'exp' => time() - 3600, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');
        JWT::decode($token);
    }

    public function testDecodeRejectsFutureIat(): void
    {
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => time() + 120, 'exp' => time() + 7200, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('future');
        JWT::decode($token);
    }

    public function testDecodeRejectsNotYetValidNbf(): void
    {
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 7200, 'nbf' => time() + 600, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not yet valid');
        JWT::decode($token);
    }

    public function testDecodeAcceptsNbfInPast(): void
    {
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => time() - 100, 'exp' => time() + 3600, 'nbf' => time() - 50, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $decoded = JWT::decode($token);
        $this->assertEquals(1, $decoded['sub']);
    }

    public function testDecodeRejectsZeroSub(): void
    {
        $payload = ['sub' => 0, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sub');
        JWT::decode($token);
    }

    public function testDecodeRejectsNegativeSub(): void
    {
        $payload = ['sub' => -1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sub');
        JWT::decode($token);
    }

    public function testDecodeRejectsZeroVer(): void
    {
        $payload = ['sub' => 1, 'ver' => 0, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ver');
        JWT::decode($token);
    }

    public function testDecodeRejectsInvalidType(): void
    {
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'admin', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('type');
        JWT::decode($token);
    }

    public function testDecodeRejectsMalformedToken(): void
    {
        $this->expectException(\RuntimeException::class);
        JWT::decode('not.a.valid.jwt');
    }

    public function testDecodeRejectsTamperedPayload(): void
    {
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode(json_encode(['sub' => 999, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access'])), '+/', '-_'), '=');
        $tampered = implode('.', $parts);
        $this->expectException(\RuntimeException::class);
        JWT::decode($tampered);
    }

    public function testDecodeRejectsTwoPartToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('structure');
        JWT::decode('header.payload');
    }

    public function testDecodeRejectsFourPartToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('structure');
        JWT::decode('a.b.c.d');
    }

    // ================================================================
    // JWT — encode/decode round-trip
    // ================================================================

    public function testEncodeAccessContainsRequiredClaims(): void
    {
        $token = JWT::encodeAccess(42, 3, 3600);
        $decoded = JWT::decode($token);
        $this->assertEquals(42, $decoded['sub']);
        $this->assertEquals(3, $decoded['ver']);
        $this->assertEquals('access', $decoded['type']);
        $this->assertArrayHasKey('exp', $decoded);
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('jti', $decoded);
    }

    public function testEncodeRefreshContainsRefreshType(): void
    {
        $token = JWT::encodeRefresh(10, 1, 604800);
        $decoded = JWT::decode($token);
        $this->assertEquals(10, $decoded['sub']);
        $this->assertEquals('refresh', $decoded['type']);
    }

    public function testEncodeAccessWithAudience(): void
    {
        $token = JWT::encodeAccess(1, 1, 3600, 'api.example.com');
        $decoded = JWT::decode($token);
        $this->assertEquals('api.example.com', $decoded['aud']);
    }

    // ================================================================
    // JWT — validateAudience boundary
    // ================================================================

    public function testValidateAudienceNullReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['sub' => 1], 'anything'));
    }

    public function testValidateAudienceExactMatchReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['aud' => 'api.example.com'], 'api.example.com'));
    }

    public function testValidateAudienceMismatchReturnsFalse(): void
    {
        $this->assertFalse(JWT::validateAudience(['aud' => 'api.example.com'], 'other.example.com'));
    }

    public function testValidateAudienceArrayContainsReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['aud' => ['api.example.com', 'other.example.com']], 'api.example.com'));
    }

    public function testValidateAudienceArrayMissingReturnsFalse(): void
    {
        $this->assertFalse(JWT::validateAudience(['aud' => ['api.example.com']], 'other.example.com'));
    }

    // ================================================================
    // JWT — algorithm validation
    // ================================================================

    public function testRejectsUnsupportedAlgorithm(): void
    {
        putenv('JWT_ALGORITHM=RS512');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported');
        JWT::encode(['sub' => 1]);
    }

    // ================================================================
    // JWT — key version and rotation
    // ================================================================

    public function testKeyVersionDefaultIsOne(): void
    {
        JWT::reset();
        putenv('JWT_KEY_VERSION=1');
        $this->assertEquals('1', JWT::getKeyVersion());
    }

    public function testSetKeyVersionOverridesDefault(): void
    {
        JWT::setKeyVersion('5');
        $this->assertEquals('5', JWT::getKeyVersion());
    }

    public function testResetClearsKeyVersion(): void
    {
        JWT::setKeyVersion('99');
        JWT::reset();
        putenv('JWT_KEY_VERSION=7');
        $this->assertEquals('7', JWT::getKeyVersion());
    }

    // ================================================================
    // JWT — secret validation
    // ================================================================

    /**
     * Set a value directly in $_ENV (highest priority in Env::get), run $fn, restore.
     */
    private function withEnv(string $key, ?string $value, callable $fn): void
    {
        $backup = $_ENV[$key] ?? null;
        if ($value === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
        }
        try {
            $fn();
        } finally {
            if ($backup === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $backup;
            }
        }
    }

    public function testRejectsEmptySecret(): void
    {
        JWT::reset();
        $this->withEnv('JWT_SECRET', '', function (): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not configured');
            JWT::encode(['sub' => 1]);
        });
    }

    public function testRejectsShortSecret(): void
    {
        JWT::reset();
        $this->withEnv('JWT_SECRET', 'short', function (): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('weak');
            JWT::encode(['sub' => 1]);
        });
    }

    public function testRejectsPlaceholderSecret(): void
    {
        JWT::reset();
        $this->withEnv('JWT_SECRET', 'please_set_this_to_a_real_secret_now', function (): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('weak');
            JWT::encode(['sub' => 1]);
        });
    }

    public function testRejectsChangeThisSecret(): void
    {
        JWT::reset();
        $this->withEnv('JWT_SECRET', 'change_this_to_something_secure_32chars', function (): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('weak');
            JWT::encode(['sub' => 1]);
        });
    }

    // ================================================================
    // JWT — blacklist
    // ================================================================

    public function testBlacklistedJtiIsRejected(): void
    {
        $jti = bin2hex(random_bytes(16));
        JWT::blacklistJti($jti, time() + 3600);
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => $jti];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revoked');
        JWT::decode($token);
    }

    // ================================================================
    // JWT — key rotation
    // ================================================================

    public function testOldSecretTokenAcceptedDuringRotationGracePeriod(): void
    {
        // Real rotation semantics: after one rotation JWT_KEY_VERSION becomes 2,
        // tokens signed with the previous secret remain valid during the grace window.
        $oldSecret = 'old-secret-key-that-is-long-enough-for-32';
        $newSecret = 'new-secret-key-that-is-long-enough-for-32';

        JWT::reset();
        $token = null;
        $this->withEnv('JWT_SECRET', $oldSecret, function () use (&$token): void {
            $token = JWT::encodeAccess(1, 1, 3600);
        });
        JWT::reset();

        $this->withEnv('JWT_SECRET', $newSecret, function () use ($oldSecret, $token): void {
            $this->withEnv('JWT_PREVIOUS_SECRET', $oldSecret, function () use ($token): void {
                $this->withEnv('JWT_KEY_VERSION', '2', function () use ($token): void {
                    JWT::reset();
                    $decoded = JWT::decode($token);
                    $this->assertSame(1, $decoded['sub']);
                });
            });
        });
    }

    public function testTokenWithWrongSecretFails(): void
    {
        JWT::reset();
        $token = JWT::encodeAccess(1, 1, 3600);
        JWT::reset();

        $this->withEnv('JWT_SECRET', 'wrong-secret-key-long-enough-for-thirtytwo', function () use ($token): void {
            $this->withEnv('JWT_PREVIOUS_SECRET', null, function () use ($token): void {
                $this->expectException(\RuntimeException::class);
                JWT::decode($token);
            });
        });
    }

    // ================================================================
    // Cache — remember and basic operations
    // ================================================================

    public function testCacheRememberReturnsExistingValue(): void
    {
        Cache::set('mut_test_key_rt', 'existing', 60);
        $result = Cache::remember('mut_test_key_rt', 60, fn() => 'new_value');
        $this->assertEquals('existing', $result);
    }

    public function testCacheRememberCallsCallbackOnMiss(): void
    {
        $result = Cache::remember('mut_test_miss_' . bin2hex(random_bytes(4)), 60, fn() => 'computed');
        $this->assertEquals('computed', $result);
    }

    public function testCacheGetReturnsNullOnMiss(): void
    {
        $this->assertNull(Cache::get('mut_nonexistent_key_' . bin2hex(random_bytes(4))));
    }

    public function testCacheSetAndGetRoundTrip(): void
    {
        $key = 'mut_rt_' . bin2hex(random_bytes(4));
        Cache::set($key, 'hello', 60);
        $this->assertEquals('hello', Cache::get($key));
    }

    public function testCacheForgetRemovesKey(): void
    {
        $key = 'mut_del_' . bin2hex(random_bytes(4));
        Cache::set($key, 'value', 60);
        Cache::forget($key);
        $this->assertNull(Cache::get($key));
    }

    public function testCacheFlushClearsAll(): void
    {
        Cache::flush();
        $this->assertNull(Cache::get('mut_flush_test_' . bin2hex(random_bytes(4))));
    }

    // ================================================================
    // JWT — exact claim arithmetic (kills Decrement/Increment on TTLs)
    // ================================================================

    public function testEncodeAccessDefaultTtlIsExactly3600(): void
    {
        $decoded = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertSame(3600, $decoded['exp'] - $decoded['iat']);
    }

    public function testEncodeRefreshDefaultTtlIsExactly604800(): void
    {
        $decoded = JWT::decode(JWT::encodeRefresh(1, 1));
        $this->assertSame(604800, $decoded['exp'] - $decoded['iat']);
    }

    public function testEncodeAccessClampsTokenVersionToMinimum1(): void
    {
        $decoded = JWT::decode(JWT::encodeAccess(1, 0));
        $this->assertSame(1, $decoded['ver']);
        $decoded2 = JWT::decode(JWT::encodeAccess(1, -5));
        $this->assertSame(1, $decoded2['ver']);
    }

    public function testEncodeRefreshClampsTokenVersionToMinimum1(): void
    {
        $decoded = JWT::decode(JWT::encodeRefresh(1, 0));
        $this->assertSame(1, $decoded['ver']);
    }

    public function testEncodeRefreshUsesProvidedJti(): void
    {
        $decoded = JWT::decode(JWT::encodeRefresh(1, 1, 604800, 'my-custom-jti'));
        $this->assertSame('my-custom-jti', $decoded['jti']);
    }

    // ================================================================
    // JWT — issuer (kills rtrim/ternary mutants at line 88)
    // ================================================================

    public function testIssuerStripsTrailingSlashFromAppUrl(): void
    {
        $this->withEnv('APP_URL', 'https://api.example.com/', function (): void {
            JWT::reset();
            $decoded = JWT::decode(JWT::encodeAccess(1, 1));
            $this->assertSame('https://api.example.com', $decoded['iss']);
        });
    }

    public function testIssuerFallsBackWhenAppUrlEmpty(): void
    {
        $this->withEnv('APP_URL', '', function (): void {
            JWT::reset();
            $decoded = JWT::decode(JWT::encodeAccess(1, 1));
            $this->assertSame('siro-api', $decoded['iss']);
        });
    }

    // ================================================================
    // JWT — iat future boundary at exactly +60s (kills GreaterThan)
    // ================================================================

    public function testDecodeAcceptsIatAtExactlyPlus60WithinClockSkew(): void
    {
        // iat exactly at the +60s skew limit must still validate (strict > comparison)
        $now = time();
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => $now + 60, 'exp' => $now + 7200, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $decoded = JWT::decode($token);
        $this->assertSame(1, $decoded['sub']);
    }

    public function testDecodeAcceptsIatJustInsideClockSkewLimit(): void
    {
        $now = time();
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => $now + 59, 'exp' => $now + 7200, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $decoded = JWT::decode($token);
        $this->assertSame(1, $decoded['sub']);
    }

    // ================================================================
    // JWT — missing claims (kills default-value mutations)
    // ================================================================

    public function testDecodeRejectsTokenMissingExpClaim(): void
    {
        $payload = ['sub' => 1, 'ver' => 1, 'iat' => time(), 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');
        JWT::decode($token);
    }

    public function testDecodeRejectsTokenMissingSubClaim(): void
    {
        $payload = ['ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sub');
        JWT::decode($token);
    }

    public function testDecodeRejectsTokenMissingVerClaim(): void
    {
        $payload = ['sub' => 5, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($payload);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ver');
        JWT::decode($token);
    }

    // ================================================================
    // JWT — secret strength boundaries (kills str_contains/strlen mutants)
    // ================================================================

    public function testRejectsUppercasePlaceholderSecret(): void
    {
        JWT::reset();
        $this->withEnv('JWT_SECRET', 'YOUR_SECRET_GOES_HERE_abcdef_0123456789', function (): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('weak');
            JWT::encode(['sub' => 1]);
        });
    }

    public function testAcceptsSecretOfExactly32Characters(): void
    {
        JWT::reset();
        $this->withEnv('JWT_SECRET', 'abcdefghijklmnopqrstuvwxyz012345', function (): void {
            JWT::reset();
            $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 60, 'type' => 'access']);
            $this->assertArrayHasKey('sub', JWT::decode($token));
        });
    }

    // ================================================================
    // JWT — base64 decoding (kills strict-decode FalseValue mutant)
    // ================================================================

    public function testDecodeRejectsInvalidBase64Segment(): void
    {
        // Three-part token whose segments contain invalid base64url characters
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid base64 token segment');
        JWT::decode('@@@.@@@.@@@');
    }

    // ================================================================
    // JWT — RS256 algorithm path (kills match-arm removal)
    // ================================================================

    public function testRs256RoundTripWithGeneratedKeys(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKey);
        $details = openssl_pkey_get_details($keyPair);
        $publicKey = $details['key'];

        $this->withEnv('JWT_ALGORITHM', 'RS256', function () use ($privateKey, $publicKey): void {
            $this->withEnv('JWT_PRIVATE_KEY', $privateKey, function () use ($publicKey): void {
                $this->withEnv('JWT_PUBLIC_KEY', $publicKey, function (): void {
                    JWT::reset();
                    $token = JWT::encodeAccess(7, 2, 600);
                    $decoded = JWT::decode($token);
                    $this->assertSame(7, $decoded['sub']);
                    $this->assertSame(2, $decoded['ver']);
                });
            });
        });
    }

    public function testRs256RejectsTamperedToken(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKey);
        $details = openssl_pkey_get_details($keyPair);
        $publicKey = $details['key'];

        $this->withEnv('JWT_ALGORITHM', 'RS256', function () use ($privateKey, $publicKey): void {
            $this->withEnv('JWT_PRIVATE_KEY', $privateKey, function () use ($publicKey): void {
                $this->withEnv('JWT_PUBLIC_KEY', $publicKey, function (): void {
                    JWT::reset();
                    $token = JWT::encodeAccess(7, 2, 600);
                    $parts = explode('.', $token);
                    $parts[2] = str_repeat('A', strlen($parts[2]));
                    $this->expectException(\RuntimeException::class);
                    $this->expectExceptionMessage('signature');
                    JWT::decode(implode('.', $parts));
                });
            });
        });
    }

    // ================================================================
    // ApiKey — exact validate shape (kills casts/coalesces lines 102-118)
    // ================================================================

    public function testApiKeyValidateReturnsExactShape(): void
    {
        $created = ApiKey::create('Shape Key', 'read,write', 42, 0);
        $data = ApiKey::validate($created['token']);
        $this->assertNotNull($data);
        $this->assertIsInt($data['id']);
        $this->assertGreaterThan(0, $data['id']);
        $this->assertSame('Shape Key', $data['name']);
        $this->assertSame('read,write', $data['scopes']);
        $this->assertSame(42, $data['user_id']);
        $this->assertNotEmpty($data['created_at']);
        $this->assertNull($data['expires_at']);
    }

    public function testApiKeyWithExpiryReturnsFormattedDate(): void
    {
        $created = ApiKey::create('Dated', 'read', null, 7);
        $this->assertNotNull($created['expires_at']);
        $data = ApiKey::validate($created['token']);
        $this->assertNotNull($data);
        $this->assertNotNull($data['expires_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['expires_at']);
    }

    public function testApiKeyExpiredTokenIsRejected(): void
    {
        $created = ApiKey::create('Old', 'read', null, 1);
        // Force the row to be expired by rewriting expires_at into the past
        $hashed = hash('sha256', $created['token']);
        Database::execute('UPDATE api_keys SET expires_at = ? WHERE token_hash = ?', [time() - 100, $hashed]);
        $this->assertNull(ApiKey::validate($created['token']));
    }

    public function testApiKeyExpiryBoundaryAtExactlyNowIsStillValid(): void
    {
        // Contract: key expires only when expires_at < now (strictly in the past).
        // A key expiring this very second is still valid.
        $created = ApiKey::create('Boundary', 'read', null, 1);
        $hashed = hash('sha256', $created['token']);
        Database::execute('UPDATE api_keys SET expires_at = ? WHERE token_hash = ?', [time(), $hashed]);
        $this->assertNotNull(ApiKey::validate($created['token']));
    }

    public function testApiKeyLegacyRowWithoutBcryptIsUpgraded(): void
    {
        $created = ApiKey::create('Legacy', 'read', null, 0);
        $hashed = hash('sha256', $created['token']);
        Database::execute('UPDATE api_keys SET token_bcrypt = NULL WHERE token_hash = ?', [$hashed]);
        // First validate migrates to bcrypt and succeeds
        $data = ApiKey::validate($created['token']);
        $this->assertNotNull($data);
        // bcrypt was persisted
        $row = Database::select('SELECT token_bcrypt FROM api_keys WHERE token_hash = ?', [$hashed]);
        $this->assertNotEmpty($row[0]['token_bcrypt']);
        // Second validate verifies via bcrypt
        $this->assertNotNull(ApiKey::validate($created['token']));
    }

    public function testApiKeyWrongTokenOnLegacyRowFails(): void
    {
        $created = ApiKey::create('Legacy2', 'read', null, 0);
        $hashed = hash('sha256', $created['token']);
        Database::execute('UPDATE api_keys SET token_bcrypt = NULL WHERE token_hash = ?', [$hashed]);
        $this->assertNull(ApiKey::validate('wrong-token-value-0000'));
    }

    public function testApiKeyRowWithNullExpiresAtNeverExpires(): void
    {
        $created = ApiKey::create('NullExp', 'read', null, 0);
        $hashed = hash('sha256', $created['token']);
        Database::execute('UPDATE api_keys SET expires_at = NULL WHERE token_hash = ?', [$hashed]);
        $data = ApiKey::validate($created['token']);
        $this->assertNotNull($data);
        $this->assertNull($data['expires_at']);
    }

    // ================================================================
    // ApiKey — hasScope (kills trim/cast mutants lines 206-207)
    // ================================================================

    public function testHasScopeTrueForExactScope(): void
    {
        $created = ApiKey::create('Scoped', 'read,write', null, 0);
        $this->assertTrue(ApiKey::hasScope($created['token'], 'read'));
        $this->assertTrue(ApiKey::hasScope($created['token'], 'write'));
    }

    public function testHasScopeFalseForMissingScope(): void
    {
        $created = ApiKey::create('Scoped2', 'read', null, 0);
        $this->assertFalse(ApiKey::hasScope($created['token'], 'write'));
    }

    public function testHasScopeTrimsWhitespaceAroundScopes(): void
    {
        $created = ApiKey::create('Spaced', ' read , write ', null, 0);
        $this->assertTrue(ApiKey::hasScope($created['token'], 'read'));
        $this->assertTrue(ApiKey::hasScope($created['token'], 'write'));
    }

    public function testHasScopeAdminGrantsEverything(): void
    {
        $created = ApiKey::create('Admin', 'admin', null, 0);
        $this->assertTrue(ApiKey::hasScope($created['token'], 'anything'));
    }

    public function testHasScopeCaseInsensitiveScopeName(): void
    {
        $created = ApiKey::create('Mixed', 'READ,Write', null, 0);
        $this->assertTrue(ApiKey::hasScope($created['token'], 'read'));
        $this->assertTrue(ApiKey::hasScope($created['token'], 'WRITE'));
    }

    public function testHasScopeInvalidTokenReturnsFalse(): void
    {
        $this->assertFalse(ApiKey::hasScope('no-such-token', 'read'));
    }

    // ================================================================
    // ApiKey — listForUser is_expired (kills line 177-183 mutants)
    // ================================================================

    public function testListForUserMarksExpiredKeys(): void
    {
        $userId = 777001;
        $live = ApiKey::create('Live', 'read', $userId, 0);
        $dead = ApiKey::create('Dead', 'read', $userId, 1);
        $deadHash = hash('sha256', $dead['token']);
        Database::execute('UPDATE api_keys SET expires_at = ? WHERE token_hash = ?', [time() - 50, $deadHash]);

        $list = ApiKey::listForUser($userId);
        $this->assertCount(2, $list);
        $byName = [];
        foreach ($list as $row) {
            $byName[$row['name']] = $row;
        }
        $this->assertFalse($byName['Live']['is_expired']);
        $this->assertTrue($byName['Dead']['is_expired']);
    }

    public function testListForUserWithoutFilterReturnsAll(): void
    {
        ApiKey::create('U1', 'read', 888001, 0);
        ApiKey::create('U2', 'read', 888002, 0);
        $all = ApiKey::listForUser(null);
        $this->assertGreaterThanOrEqual(2, count($all));
        // Each row exposes listing fields, never token material
        foreach ($all as $row) {
            $this->assertArrayNotHasKey('token_hash', $row);
            $this->assertArrayNotHasKey('token_bcrypt', $row);
            $this->assertArrayHasKey('is_expired', $row);
        }
    }

    public function testRevokeAllForUserReturnsAffectedCount(): void
    {
        $userId = 999001;
        ApiKey::create('A', 'read', $userId, 0);
        ApiKey::create('B', 'read', $userId, 0);
        $this->assertSame(2, ApiKey::revokeAllForUser($userId));
        $this->assertSame(0, ApiKey::revokeAllForUser($userId));
    }

    public function testCreateScopesAreNormalizedToLowerCase(): void
    {
        $created = ApiKey::create('Norm', '  READ , Write  ', null, 0);
        $this->assertSame('read , write', $created['scopes']);
    }
}
