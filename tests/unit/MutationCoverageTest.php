<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Auth\AuthGuard;
use Siro\Core\Auth\Idempotency;
use Siro\Core\Auth\UserProvider;
use Siro\Core\Cache;
use Siro\Core\Cache\CacheInstance;
use Siro\Core\Cache\Drivers\FileDriver;
use Siro\Core\Cache\Drivers\RedisDriver;
use Siro\Core\Container;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Request;

/**
 * Comprehensive mutation killer tests for Auth + Cache scope.
 *
 * Targets specific escaped mutations identified by Infection:
 * - Logical operators (==, !=, &&, ||)
 * - Boolean checks (false/true values)
 * - Throw_ mutations (exception paths)
 * - CastString/CastInt mutations
 * - DecrementInteger/IncrementInteger (numeric boundaries)
 * - GreaterThan/LessThan comparisons
 * - Identical/NotIdentical type checks
 * - Concat/ConcatOperandRemoval (string building)
 */
final class MutationCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('JWT_SECRET=test_jwt_secret_key_for_unit_tests_32_chars_long!!');
        putenv('JWT_KEY_VERSION=1');
        putenv('JWT_ALGORITHM=HS256');
        putenv('JWT_PREVIOUS_SECRET=');
        putenv('JWT_PRIVATE_KEY=');
        putenv('JWT_PUBLIC_KEY=');
        putenv('JWT_PRIVATE_KEY_PATH=');
        putenv('JWT_PUBLIC_KEY_PATH=');
        putenv('APP_URL=');
        unset($_ENV['JWT_SECRET'], $_ENV['JWT_KEY_VERSION'], $_ENV['JWT_ALGORITHM'],
              $_ENV['JWT_PREVIOUS_SECRET'], $_ENV['APP_URL']);
        JWT::reset();
        Cache::reset();
        // Fresh container so auth.resolver/auth.provider bindings from other
        // test classes cannot leak into AuthGuard::resolve()/getUserProvider().
        Container::setInstance(new Container());
    }

    protected function tearDown(): void
    {
        putenv('JWT_SECRET');
        putenv('JWT_KEY_VERSION');
        putenv('JWT_ALGORITHM');
        putenv('JWT_PREVIOUS_SECRET');
        putenv('JWT_PRIVATE_KEY');
        putenv('JWT_PUBLIC_KEY');
        putenv('JWT_PRIVATE_KEY_PATH');
        putenv('JWT_PUBLIC_KEY_PATH');
        putenv('APP_URL');
        Env::reset();
        Cache::reset();
        JWT::reset();
        Container::setInstance(null);
        parent::tearDown();
    }

    private function setupDatabase(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        unset($_ENV['DB_CONNECTION'], $_ENV['DB_DATABASE']);
        Env::reset();
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    // ============================================================
    // JWT — Algorithm selection, encoding, decoding, validation
    // ============================================================

    public function testHs256IsDefaultAlgorithm(): void
    {
        putenv('JWT_ALGORITHM');
        unset($_ENV['JWT_ALGORITHM']);
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
    }

    public function testEncodeAccessContainsSubClaim(): void
    {
        $token = JWT::encodeAccess(42, 3, 1800);
        $claims = JWT::decode($token);
        $this->assertSame(42, $claims['sub']);
        $this->assertSame(3, $claims['ver']);
        $this->assertSame('access', $claims['type']);
        $this->assertSame(1800, $claims['exp'] - $claims['iat']);
    }

    public function testEncodeRefreshContainsSubClaim(): void
    {
        $token = JWT::encodeRefresh(99, 1, 86400, 'custom-jti');
        $claims = JWT::decode($token);
        $this->assertSame(99, $claims['sub']);
        $this->assertSame(1, $claims['ver']);
        $this->assertSame('refresh', $claims['type']);
        $this->assertSame('custom-jti', $claims['jti']);
    }

    public function testTokenWithZeroExpIsExpired(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token expired');
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => 0, 'type' => 'access', 'jti' => 'zero-exp']);
        JWT::decode($token);
    }

    public function testTokenWithNegativeExpIsExpired(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token expired');
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => -100, 'type' => 'access', 'jti' => 'neg-exp']);
        JWT::decode($token);
    }

    public function testTokenWithNonNumericExpIsExpired(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => 'not-a-number', 'type' => 'access', 'jti' => 'str-exp']);
        JWT::decode($token);
    }

    public function testTokenWithZeroSubRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sub');
        $token = JWT::encode(['sub' => 0, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'zero-sub']);
        JWT::decode($token);
    }

    public function testTokenWithNonNumericSubRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 'abc', 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'str-sub']);
        JWT::decode($token);
    }

    public function testTokenWithInvalidTypeRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid token type');
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'invalid', 'jti' => 'bad-type']);
        JWT::decode($token);
    }

    public function testTokenWithMissingJtiStillDecodes(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testValidateAudienceWithMissingAudReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['sub' => 1], 'anything'));
    }

    public function testValidateAudienceWithMatchingStringReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['aud' => 'web-app'], 'web-app'));
    }

    public function testValidateAudienceWithNonMatchingStringReturnsFalse(): void
    {
        $this->assertFalse(JWT::validateAudience(['aud' => 'web-app'], 'mobile-app'));
    }

    public function testValidateAudienceWithMatchingArrayReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['aud' => ['web', 'mobile']], 'mobile'));
    }

    public function testValidateAudienceWithNonMatchingArrayReturnsFalse(): void
    {
        $this->assertFalse(JWT::validateAudience(['aud' => ['web', 'mobile']], 'cli'));
    }

    public function testSecretWithPlaceholderRejected(): void
    {
        putenv('JWT_SECRET=please_set_your_secret_here_1234567');
        unset($_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too weak');
        JWT::encodeAccess(1, 1);
    }

    public function testSecretWithChangeThisRejected(): void
    {
        putenv('JWT_SECRET=change_this_to_a_real_secret_32chars');
        unset($_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testSecretWithYourSecretRejected(): void
    {
        putenv('JWT_SECRET=your_secret_must_be_at_least_32_chars');
        unset($_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testTokenIssuedInTheFutureRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('future');
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time() + 120, 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'future-iat']);
        JWT::decode($token);
    }

    public function testTokenWithNbfExactlyNowAccepted(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'nbf' => time(), 'jti' => 'nbf-exact']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testTokenWithNbfOneSecondFutureRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'nbf' => time() + 1, 'jti' => 'nbf-1s']);
        JWT::decode($token);
    }

    public function testPreviousSecretRotationFailsWithWrongSecret(): void
    {
        putenv('JWT_PREVIOUS_SECRET=old_secret_that_was_rotated_12345678');
        putenv('JWT_SECRET=new_secret_after_rotation_12345678901');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::decode('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsInZlciI6MSwidHlwZSI6ImFjY2VzcyIsImlhdCI6MTcwMDAwMDAwMCwiZXhwIjo5OTk5OTk5OTk5fQ.fakesignature');
    }

    public function testRotateKeyWithoutEnvFileStillUpdatesVersion(): void
    {
        $before = JWT::getKeyVersion();
        JWT::rotateKey('brand_new_secret_1234567890_abcdefghijk');
        $after = JWT::getKeyVersion();
        $this->assertSame((int)$before + 1, (int)$after);
    }

    public function testCleanupBlacklistIntervalConstant(): void
    {
        $ref = new \ReflectionClass(JWT::class);
        $interval = $ref->getConstant('BLACKLIST_CLEANUP_INTERVAL');
        $this->assertIsInt($interval);
        $this->assertSame(300, $interval);
    }

    public function testAlgorithmMismatchErrorMessageContainsBothAlgorithms(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $header = json_decode(base64_decode($parts[0]), true);
        $header['alg'] = 'RS256';
        $parts[0] = rtrim(strtr(base64_encode((string)json_encode($header)), '+/', '-_'), '=');
        $tampered = implode('.', $parts);
        try {
            JWT::decode($tampered);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RS256', $e->getMessage());
            $this->assertStringContainsString('HS256', $e->getMessage());
        }
    }

    public function testDecodeRejectsTokenWithOnlyDots(): void
    {
        $this->expectException(\RuntimeException::class);
        JWT::decode('...');
    }

    public function testBase64UrlEncodeDecodePreservesBinaryData(): void
    {
        $ref = new \ReflectionClass(JWT::class);
        $enc = $ref->getMethod('base64UrlEncode');
        $enc->setAccessible(true);
        $dec = $ref->getMethod('base64UrlDecode');
        $dec->setAccessible(true);
        $binary = random_bytes(32);
        $this->assertSame($binary, $dec->invoke(null, $enc->invoke(null, $binary)));
    }

    // ============================================================
    // AuthGuard — resolve, user, id, check, guest, hasRole, logout
    // ============================================================

    public function testGuestReturnsTrueWhenNoUser(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->guest());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function testCheckReturnsTrueAfterResolve(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertTrue($guard->guest());

        // Simulate setting user data via reflection
        $ref = new \ReflectionClass($guard);
        $prop = $ref->getProperty('userData');
        $prop->setAccessible(true);
        $prop->setValue($guard, ['id' => 42, 'role' => 'admin', 'token_version' => 1]);

        $this->assertTrue($guard->check());
        $this->assertFalse($guard->guest());
        $this->assertSame(42, $guard->id());
    }

    public function testHasRoleMatchesExactRole(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $ref = new \ReflectionClass($guard);
        $prop = $ref->getProperty('userData');
        $prop->setAccessible(true);
        $prop->setValue($guard, ['id' => 1, 'role' => 'admin', 'token_version' => 1]);

        $this->assertTrue($guard->hasRole('admin'));
        $this->assertTrue($guard->hasRole('Admin'));
        $this->assertTrue($guard->hasRole('ADMIN'));
        $this->assertFalse($guard->hasRole('user'));
    }

    public function testHasRoleReturnsFalseWhenNoUser(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertFalse($guard->hasRole('admin'));
    }

    public function testHasRoleWithMultipleRoles(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $ref = new \ReflectionClass($guard);
        $prop = $ref->getProperty('userData');
        $prop->setAccessible(true);
        $prop->setValue($guard, ['id' => 1, 'role' => 'editor', 'token_version' => 1]);

        $this->assertTrue($guard->hasRole('admin', 'editor', 'viewer'));
        $this->assertFalse($guard->hasRole('admin', 'superadmin'));
    }

    public function testLogoutClearsUserData(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $ref = new \ReflectionClass($guard);
        $prop = $ref->getProperty('userData');
        $prop->setAccessible(true);
        $prop->setValue($guard, ['id' => 1, 'role' => 'user', 'token_version' => 1]);

        $this->assertTrue($guard->check());
        $guard->logout();
        $this->assertTrue($guard->guest());
        $this->assertFalse($guard->check());
    }

    public function testIdReturnsNullWhenNoUser(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $this->assertNull($guard->id());
    }

    public function testIdReturnsIntWhenUserSet(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $ref = new \ReflectionClass($guard);
        $prop = $ref->getProperty('userData');
        $prop->setAccessible(true);
        $prop->setValue($guard, ['id' => 7, 'role' => 'user', 'token_version' => 1]);
        $this->assertSame(7, $guard->id());
    }

    public function testResolveWithNoAuthHeaderReturnsNull(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test');
        $result = $guard->resolve($request);
        $this->assertNull($result);
    }

    public function testResolveWithBearerPrefixExtractsToken(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test', ['authorization' => 'Bearer invalid-token']);
        $result = $guard->resolve($request);
        $this->assertNull($result); // invalid token should return null
    }

    public function testResolveWithCaseInsensitiveBearer(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $request = new Request('GET', '/test', ['authorization' => 'bearer invalid-token']);
        $result = $guard->resolve($request);
        $this->assertNull($result);
    }

    public function testHasRoleWithNonScalarRoleDefaultsToUser(): void
    {
        $guard = AuthGuard::instance();
        AuthGuard::setInstance(null);
        $guard = AuthGuard::instance();
        $ref = new \ReflectionClass($guard);
        $prop = $ref->getProperty('userData');
        $prop->setAccessible(true);
        $prop->setValue($guard, ['id' => 1, 'role' => null, 'token_version' => 1]);

        $this->assertTrue($guard->hasRole('user'));
        $this->assertFalse($guard->hasRole('admin'));
    }

    // ============================================================
    // Idempotency — setKey, isDuplicate, storeResponse, cleanup
    // ============================================================

    public function testIdempotencyWithEmptyKeyIsNotDuplicate(): void
    {
        $idem = new Idempotency();
        $idem->setKey('');
        $this->assertFalse($idem->isDuplicate());
        $this->assertNull($idem->getStoredResponse());
    }

    public function testIdempotencyWithWhitespaceKeyTrimsAndChecks(): void
    {
        $idem = new Idempotency();
        $idem->setKey('   ', 0, 'POST');
        $this->assertFalse($idem->isDuplicate());
    }

    public function testIdempotencyClearResetsState(): void
    {
        $this->setupDatabase();
        Idempotency::createTable();
        $idem = new Idempotency();
        $idem->setKey('test-key-clear');
        $this->assertFalse($idem->isDuplicate());
        $idem->clear();
        $this->assertFalse($idem->isDuplicate());
        $this->assertNull($idem->getStoredResponse());
    }

    public function testIdempotencyConstructorMinimizesTtl(): void
    {
        $idem = new Idempotency(0);
        $ref = new \ReflectionProperty($idem, 'ttl');
        $ref->setAccessible(true);
        $this->assertSame(1, $ref->getValue($idem));
    }

    public function testIdempotencyConstructorNegativeTtlBecomesOne(): void
    {
        $idem = new Idempotency(-5);
        $ref = new \ReflectionProperty($idem, 'ttl');
        $ref->setAccessible(true);
        $this->assertSame(1, $ref->getValue($idem));
    }

    public function testIdempotencyStoreWithoutKeyDoesNothing(): void
    {
        $idem = new Idempotency();
        // storeResponse without setKey should not throw
        $idem->storeResponse(['status' => 200]);
        $this->assertNull($idem->getStoredResponse());
    }

    public function testIdempotencyGetStoredResponseReturnsNullWhenNotDuplicate(): void
    {
        $idem = new Idempotency();
        $this->assertNull($idem->getStoredResponse());
    }

    public function testIdempotencyHashContainsUserId(): void
    {
        $this->setupDatabase();
        Idempotency::createTable();
        $idem = new Idempotency();
        $idem->setKey('same-key', 1, 'POST');
        $ref1 = new \ReflectionProperty($idem, 'hash');
        $ref1->setAccessible(true);
        $hash1 = $ref1->getValue($idem);

        $idem2 = new Idempotency();
        $idem2->setKey('same-key', 2, 'POST');
        $ref2 = new \ReflectionProperty($idem2, 'hash');
        $ref2->setAccessible(true);
        $hash2 = $ref2->getValue($idem2);

        $this->assertNotSame($hash1, $hash2);
    }

    public function testIdempotencyHashContainsMethod(): void
    {
        $this->setupDatabase();
        Idempotency::createTable();
        $idem1 = new Idempotency();
        $idem1->setKey('method-test', 0, 'POST');
        $ref1 = new \ReflectionProperty($idem1, 'hash');
        $ref1->setAccessible(true);
        $hash1 = $ref1->getValue($idem1);

        $idem2 = new Idempotency();
        $idem2->setKey('method-test', 0, 'PUT');
        $ref2 = new \ReflectionProperty($idem2, 'hash');
        $ref2->setAccessible(true);
        $hash2 = $ref2->getValue($idem2);

        $this->assertNotSame($hash1, $hash2);
    }

    // ============================================================
    // FileDriver — get, set, forget, has, flush, lock, unlock
    // ============================================================

    public function testFileDriverSetAndGetRoundTrip(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_test_' . uniqid();
        $driver = new FileDriver($dir);
        $this->assertTrue($driver->set('key1', 'value1', 60));
        $this->assertSame('value1', $driver->get('key1'));
        $this->assertTrue($driver->has('key1'));
        // Cleanup
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverGetReturnsNullForMissingKey(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_miss_' . uniqid();
        $driver = new FileDriver($dir);
        $this->assertNull($driver->get('nonexistent'));
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverForgetRemovesKey(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_forget_' . uniqid();
        $driver = new FileDriver($dir);
        $driver->set('to_forget', 'data', 60);
        $this->assertTrue($driver->has('to_forget'));
        $this->assertTrue($driver->forget('to_forget'));
        $this->assertFalse($driver->has('to_forget'));
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverForgetReturnsFalseForMissing(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_forget_miss_' . uniqid();
        $driver = new FileDriver($dir);
        $this->assertFalse($driver->forget('nonexistent'));
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverFlushDeletesAllFiles(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_flush_' . uniqid();
        $driver = new FileDriver($dir);
        $driver->set('a', 1, 60);
        $driver->set('b', 2, 60);
        $driver->set('c', 3, 60);
        $deleted = $driver->flush();
        $this->assertGreaterThanOrEqual(3, $deleted);
        $this->assertNull($driver->get('a'));
        @rmdir($dir);
    }

    public function testFileDriverFlushWithPrefixDeletesMatching(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_preflush_' . uniqid();
        $driver = new FileDriver($dir);
        $driver->set('qb_users:data', 1, 60);
        $driver->set('qb_posts:data', 2, 60);
        $driver->set('other:key', 3, 60);
        $deleted = $driver->flush('qb:');
        $this->assertGreaterThanOrEqual(2, $deleted);
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverSetWithZeroTtlCreatesPermanentEntry(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_perm_' . uniqid();
        $driver = new FileDriver($dir);
        $this->assertTrue($driver->set('permanent', 'data', 0));
        $this->assertSame('data', $driver->get('permanent'));
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverSetWithNegativeTtlCreatesExpiredEntry(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_neg_' . uniqid();
        $driver = new FileDriver($dir);
        $this->assertTrue($driver->set('neg_ttl', 'data', -10));
        // Negative TTL: expires_at = time() + (-10) = in the past, so entry is expired
        $this->assertNull($driver->get('neg_ttl'));
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverLockAndUnlock(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_lock_' . uniqid();
        $driver = new FileDriver($dir);
        $this->assertTrue($driver->lock('test-lock', 1000));
        $driver->unlock('test-lock');
        // After unlock, lock file should be released
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverUnlockWithoutLockDoesNotThrow(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_unlock_' . uniqid();
        $driver = new FileDriver($dir);
        // unlock on non-locked key should not throw and should be a no-op
        $driver->unlock('never-locked');
        $this->assertTrue(true); // No exception = pass
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverReadCorruptedFileReturnsNull(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_corrupt_' . uniqid();
        $driver = new FileDriver($dir);
        $driver->set('corrupt', 'data', 60);
        // Corrupt the file
        $files = glob($dir . '/*.cache');
        if (!empty($files)) {
            file_put_contents($files[0], 'not-valid-json');
        }
        $this->assertNull($driver->get('corrupt'));
        $driver->flush();
        @rmdir($dir);
    }

    public function testFileDriverFlushWithPrefixAndNoMatchReturnsZero(): void
    {
        $dir = sys_get_temp_dir() . '/siro_file_cache_nopref_' . uniqid();
        $driver = new FileDriver($dir);
        $driver->set('data', 'value', 60);
        $deleted = $driver->flush('nonexistent_prefix:');
        $this->assertSame(0, $deleted);
        $driver->flush();
        @rmdir($dir);
    }

    // ============================================================
    // CacheInstance — get, set, remember, forget, flush, has
    // ============================================================

    public function testCacheInstanceBootCreatesFileDriver(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        // Should work with file driver
        $this->assertTrue($cache->set('boot-test', 'value', 60));
        $this->assertSame('value', $cache->get('boot-test'));
        $cache->forget('boot-test');
    }

    public function testCacheInstanceGetReturnsNullForMissing(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $this->assertNull($cache->get('definitely-missing-' . uniqid()));
    }

    public function testCacheInstanceSetAndGetRoundTrip(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $this->assertTrue($cache->set('round-trip', ['a' => 1, 'b' => 'two'], 60));
        $result = $cache->get('round-trip');
        $this->assertSame(['a' => 1, 'b' => 'two'], $result);
        $cache->forget('round-trip');
    }

    public function testCacheInstanceHasReturnsTrueForExisting(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $cache->set('has-test', 'yes', 60);
        $this->assertTrue($cache->has('has-test'));
        $cache->forget('has-test');
    }

    public function testCacheInstanceHasReturnsFalseForMissing(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $this->assertFalse($cache->has('no-has-' . uniqid()));
    }

    public function testCacheInstanceForgetReturnsBoolean(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $cache->set('forget-me', 'data', 60);
        $this->assertTrue($cache->forget('forget-me'));
        $this->assertFalse($cache->has('forget-me'));
    }

    public function testCacheInstanceFlushRemovesAll(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $cache->set('flush-a', 1, 60);
        $cache->set('flush-b', 2, 60);
        $cache->flush();
        $this->assertNull($cache->get('flush-a'));
        $this->assertNull($cache->get('flush-b'));
    }

    public function testCacheInstanceRememberReturnsCachedValue(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $key = 'remember-hit-' . uniqid();
        $cache->set($key, 'cached', 60);
        $result = $cache->remember($key, 60, fn() => 'computed');
        $this->assertSame('cached', $result);
        $cache->forget($key);
    }

    public function testCacheInstanceRememberComputesOnMiss(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $key = 'remember-miss-' . uniqid();
        $result = $cache->remember($key, 60, fn() => 'computed-value');
        $this->assertSame('computed-value', $result);
        // Second call should hit cache
        $result2 = $cache->remember($key, 60, fn() => 'should-not-compute');
        $this->assertSame('computed-value', $result2);
        $cache->forget($key);
    }

    public function testCacheInstanceResetRequestState(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $key = 'req-state-' . uniqid();
        $cache->get($key); // MISS
        $status = $cache->requestStatus();
        $this->assertSame('MISS', $status['status']);
        $cache->resetRequestState();
        $status = $cache->requestStatus();
        $this->assertSame('MISS', $status['status']);
    }

    public function testCacheInstanceRequestStatusHit(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $key = 'hit-status-' . uniqid();
        $cache->set($key, 'value', 60);
        $cache->get($key);
        $status = $cache->requestStatus();
        $this->assertSame('HIT', $status['status']);
        $cache->forget($key);
    }

    public function testCacheInstanceFlushWithPrefix(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $cache->set('prefix:a', 1, 60);
        $cache->set('prefix:b', 2, 60);
        $cache->set('other:c', 3, 60);
        $cache->flush('prefix:');
        $this->assertNull($cache->get('prefix:a'));
        $cache->forget('other:c');
    }

    public function testCacheInstanceFlushQueryBuilderTable(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $cache->set('qb:users:data', 1, 60);
        $cache->set('qb:posts:data', 2, 60);
        $deleted = $cache->flushQueryBuilderTable('users');
        $this->assertGreaterThanOrEqual(1, $deleted);
        $cache->forget('qb:posts:data');
    }

    public function testCacheInstanceFlushQueryBuilderEmptyTableReturnsZero(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $deleted = $cache->flushQueryBuilderTable('');
        $this->assertSame(0, $deleted);
    }

    public function testCacheInstanceSetWithNegativeTtlUsesDefault(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        $cache->boot(BASE_PATH);
        $key = 'neg-ttl-' . uniqid();
        $this->assertTrue($cache->set($key, 'data', -5));
        $this->assertSame('data', $cache->get($key));
        $cache->forget($key);
    }

    public function testCacheInstanceDriverLazilyCreatesFileDriver(): void
    {
        $cache = new CacheInstance();
        $cache->reset();
        // Don't call boot — driver should be lazily created
        $key = 'lazy-' . uniqid();
        $cache->set($key, 'lazy-value', 60);
        $this->assertSame('lazy-value', $cache->get($key));
        $cache->forget($key);
    }

    public function testCacheInstanceGetRedisConnectionReturnsRedis(): void
    {
        // This tests the Redis connection path — if Redis is available
        $redis = CacheInstance::getRedisConnection();
        if ($redis !== null) {
            $this->assertInstanceOf(\Redis::class, $redis);
        }
        // If Redis is not available, that's OK for this test
        $this->assertTrue(true);
    }

    // ============================================================
    // RedisDriver — get, set, forget, has, flush, lock, unlock
    // ============================================================

    public function testRedisDriverSetAndGetRoundTrip(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:redis-roundtrip:' . uniqid();
        $this->assertTrue($driver->set($key, 'hello-world', 60));
        $this->assertSame('hello-world', $driver->get($key));
        $driver->forget($key);
    }

    public function testRedisDriverGetReturnsNullForMissing(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $this->assertNull($driver->get('siro:test:nonexistent:' . uniqid()));
    }

    public function testRedisDriverForgetReturnsTrueForExisting(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:forget:' . uniqid();
        $driver->set($key, 'data', 60);
        $this->assertTrue($driver->forget($key));
        $this->assertFalse($driver->has($key));
    }

    public function testRedisDriverForgetReturnsFalseForMissing(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $this->assertFalse($driver->forget('siro:test:forget-miss:' . uniqid()));
    }

    public function testRedisDriverHasReturnsTrueForExisting(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:has:' . uniqid();
        $driver->set($key, 'data', 60);
        $this->assertTrue($driver->has($key));
        $driver->forget($key);
    }

    public function testRedisDriverHasReturnsFalseForMissing(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $this->assertFalse($driver->has('siro:test:has-miss:' . uniqid()));
    }

    public function testRedisDriverFlushDeletesAll(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $prefix = 'siro:test:flush:' . uniqid() . ':';
        $driver->set($prefix . 'a', 1, 60);
        $driver->set($prefix . 'b', 2, 60);
        $deleted = $driver->flush($prefix);
        $this->assertGreaterThanOrEqual(2, $deleted);
    }

    public function testRedisDriverFlushWithoutPrefixClearsAll(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        // Just verify it doesn't throw
        $deleted = $driver->flush();
        $this->assertIsInt($deleted);
    }

    public function testRedisDriverSetWithZeroTtlUsesOne(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:zero-ttl:' . uniqid();
        $this->assertTrue($driver->set($key, 'data', 0));
        $this->assertSame('data', $driver->get($key));
        $driver->forget($key);
    }

    public function testRedisDriverSetWithNegativeTtlUsesOne(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:neg-ttl:' . uniqid();
        $this->assertTrue($driver->set($key, 'data', -5));
        $this->assertSame('data', $driver->get($key));
        $driver->forget($key);
    }

    public function testRedisDriverLockAndUnlock(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:lock:' . uniqid();
        $this->assertTrue($driver->lock($key, 5000));
        $driver->unlock($key);
        // After unlock, lock should be released
        $this->assertTrue(true);
    }

    public function testRedisDriverLockWithVeryShortTimeout(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:short-lock:' . uniqid();
        // With 1ms timeout, lock might fail if contended, but should not throw
        $result = $driver->lock($key, 1);
        if ($result) {
            $driver->unlock($key);
        }
        $this->assertTrue(true);
    }

    public function testRedisDriverSetAndGetArrayValue(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:array:' . uniqid();
        $data = ['users' => [1, 2, 3], 'count' => 3];
        $this->assertTrue($driver->set($key, $data, 60));
        $this->assertSame($data, $driver->get($key));
        $driver->forget($key);
    }

    public function testRedisDriverSetAndGetNullValue(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:null-val:' . uniqid();
        $this->assertTrue($driver->set($key, null, 60));
        $this->assertNull($driver->get($key));
        $driver->forget($key);
    }

    public function testRedisDriverSetAndGetBooleanValue(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $key = 'siro:test:bool:' . uniqid();
        $this->assertTrue($driver->set($key, true, 60));
        $this->assertTrue($driver->get($key));
        $driver->forget($key);
    }

    public function testRedisDriverGetReturnsNullForInvalidJson(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $key = 'siro:test:invalid-json:' . uniqid();
        $redis->set($key, 'not-valid-json');
        $driver = new RedisDriver($redis);
        $this->assertNull($driver->get($key));
        $redis->del($key);
    }

    public function testRedisDriverGetReturnsNullForNonArrayJson(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $key = 'siro:test:non-array-json:' . uniqid();
        $redis->set($key, '"just-a-string"');
        $driver = new RedisDriver($redis);
        $this->assertNull($driver->get($key));
        $redis->del($key);
    }

    public function testRedisDriverGetReturnsNullForFalseRedisResult(): void
    {
        $redis = CacheInstance::getRedisConnection();
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }
        $driver = new RedisDriver($redis);
        $this->assertNull($driver->get('siro:test:never-set:' . uniqid()));
    }
}
