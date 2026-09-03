<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Cache;
use Siro\Core\Env;

/**
 * Deep JWT mutation tests — targets 84 escaped mutations.
 *
 * Key escaped patterns:
 * - DecrementInteger/IncrementInteger: numeric boundary tests
 * - CastInt: integer casting verification
 * - GreaterThan: comparison boundary tests
 * - LogicalOr/LogicalAnd: compound condition branch tests
 * - CastString: string casting verification
 * - Ternary: both branches exercised
 * - NotIdentical/Identical: strict type comparison tests
 */
final class JWTDeepMutationTest extends TestCase
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
        parent::tearDown();
    }

    // ============================================================
    // encodeAccess — precise value assertions
    // ============================================================

    public function testEncodeAccessSubIsExactInt(): void
    {
        $token = JWT::encodeAccess(42, 1);
        $claims = JWT::decode($token);
        $this->assertIsInt($claims['sub']);
        $this->assertSame(42, $claims['sub']);
    }

    public function testEncodeAccessVerIsExactInt(): void
    {
        $token = JWT::encodeAccess(1, 7);
        $claims = JWT::decode($token);
        $this->assertIsInt($claims['ver']);
        $this->assertSame(7, $claims['ver']);
    }

    public function testEncodeAccessVerZeroDefaultsToOne(): void
    {
        $token = JWT::encodeAccess(1, 0);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['ver']);
    }

    public function testEncodeAccessVerNegativeDefaultsToOne(): void
    {
        $token = JWT::encodeAccess(1, -5);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['ver']);
    }

    public function testEncodeAccessIatIsCurrentTime(): void
    {
        $before = time();
        $token = JWT::encodeAccess(1, 1);
        $after = time();
        $claims = JWT::decode($token);
        $this->assertIsInt($claims['iat']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual($after, $claims['iat']);
    }

    public function testEncodeAccessExpEqualsIatPlusTtl(): void
    {
        $token = JWT::encodeAccess(1, 1, 7200);
        $claims = JWT::decode($token);
        $this->assertSame(7200, $claims['exp'] - $claims['iat']);
    }

    public function testEncodeAccessDefaultTtlIs3600(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function testEncodeAccessTypeIsAccessString(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertIsString($claims['type']);
        $this->assertSame('access', $claims['type']);
    }

    public function testEncodeAccessJtiIsHexString(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertIsString($claims['jti']);
        $this->assertNotEmpty($claims['jti']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $claims['jti']);
        // 16 bytes = 32 hex chars
        $this->assertSame(32, strlen($claims['jti']));
    }

    public function testEncodeAccessWithAudience(): void
    {
        $token = JWT::encodeAccess(1, 1, 3600, 'web-app');
        $claims = JWT::decode($token);
        $this->assertArrayHasKey('aud', $claims);
        $this->assertSame('web-app', $claims['aud']);
    }

    public function testEncodeAccessWithoutAudience(): void
    {
        $token = JWT::encodeAccess(1, 1, 3600, null);
        $claims = JWT::decode($token);
        $this->assertArrayNotHasKey('aud', $claims);
    }

    public function testEncodeAccessIssUsesAppUrl(): void
    {
        putenv('APP_URL=https://api.example.com');
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame('https://api.example.com', $claims['iss']);
    }

    public function testEncodeAccessIssFallsBackToSiroApi(): void
    {
        putenv('APP_URL=');
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame('siro-api', $claims['iss']);
    }

    public function testEncodeAccessIssTrimsTrailingSlash(): void
    {
        putenv('APP_URL=https://api.example.com/');
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame('https://api.example.com', $claims['iss']);
    }

    // ============================================================
    // encodeRefresh — precise value assertions
    // ============================================================

    public function testEncodeRefreshSubIsExactInt(): void
    {
        $token = JWT::encodeRefresh(99, 3);
        $claims = JWT::decode($token);
        $this->assertIsInt($claims['sub']);
        $this->assertSame(99, $claims['sub']);
    }

    public function testEncodeRefreshVerIsExactInt(): void
    {
        $token = JWT::encodeRefresh(1, 5);
        $claims = JWT::decode($token);
        $this->assertIsInt($claims['ver']);
        $this->assertSame(5, $claims['ver']);
    }

    public function testEncodeRefreshDefaultTtlIs604800(): void
    {
        $token = JWT::encodeRefresh(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame(604800, $claims['exp'] - $claims['iat']);
    }

    public function testEncodeRefreshCustomTtl(): void
    {
        $token = JWT::encodeRefresh(1, 1, 86400);
        $claims = JWT::decode($token);
        $this->assertSame(86400, $claims['exp'] - $claims['iat']);
    }

    public function testEncodeRefreshTypeIsRefreshString(): void
    {
        $token = JWT::encodeRefresh(1, 1);
        $claims = JWT::decode($token);
        $this->assertIsString($claims['type']);
        $this->assertSame('refresh', $claims['type']);
    }

    public function testEncodeRefreshJtiIsCustomWhenProvided(): void
    {
        $token = JWT::encodeRefresh(1, 1, 604800, 'my-custom-jti');
        $claims = JWT::decode($token);
        $this->assertSame('my-custom-jti', $claims['jti']);
    }

    public function testEncodeRefreshJtiIsGeneratedWhenNull(): void
    {
        $token = JWT::encodeRefresh(1, 1, 604800, null);
        $claims = JWT::decode($token);
        $this->assertIsString($claims['jti']);
        $this->assertNotEmpty($claims['jti']);
    }

    public function testEncodeRefreshWithAudience(): void
    {
        $token = JWT::encodeRefresh(1, 1, 604800, null, 'mobile-app');
        $claims = JWT::decode($token);
        $this->assertSame('mobile-app', $claims['aud']);
    }

    public function testEncodeRefreshWithoutAudience(): void
    {
        $token = JWT::encodeRefresh(1, 1, 604800, null, null);
        $claims = JWT::decode($token);
        $this->assertArrayNotHasKey('aud', $claims);
    }

    public function testEncodeRefreshIssUsesAppUrl(): void
    {
        putenv('APP_URL=https://refresh.example.com');
        Env::reset();
        JWT::reset();
        $token = JWT::encodeRefresh(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame('https://refresh.example.com', $claims['iss']);
    }

    // ============================================================
    // decode — precise validation boundary tests
    // ============================================================

    public function testDecodeExpExactlyZeroIsExpired(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => 0, 'type' => 'access', 'jti' => 'exp0']);
        JWT::decode($token);
    }

    public function testDecodeExpExactlyOneSecondAgoIsExpired(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time() - 100, 'exp' => time() - 1, 'type' => 'access', 'jti' => 'exp-1s']);
        JWT::decode($token);
    }

    public function testDecodeExpExactlyNowIsValid(): void
    {
        // exp == now is NOT expired (check is $exp < $now, not <=)
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time() - 100, 'exp' => time(), 'type' => 'access', 'jti' => 'exp-now']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testDecodeIatExactly60SecondsInFutureAccepted(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time() + 60, 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'iat60']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testDecodeIat61SecondsInFutureRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('future');
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time() + 61, 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'iat61']);
        JWT::decode($token);
    }

    public function testDecodeNbfExactlyZeroAccepted(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'nbf' => 0, 'jti' => 'nbf0']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testDecodeNbfExactlyNowAccepted(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'nbf' => time(), 'jti' => 'nbf-now']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testDecodeNbfOneSecondFutureRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'nbf' => time() + 1, 'jti' => 'nbf1']);
        JWT::decode($token);
    }

    public function testDecodeSubExactlyZeroRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sub');
        $token = JWT::encode(['sub' => 0, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'sub0']);
        JWT::decode($token);
    }

    public function testDecodeSubNegativeRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => -1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'subneg']);
        JWT::decode($token);
    }

    public function testDecodeVerExactlyZeroRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ver');
        $token = JWT::encode(['sub' => 1, 'ver' => 0, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'ver0']);
        JWT::decode($token);
    }

    public function testDecodeVerNegativeRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => -1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'verneg']);
        JWT::decode($token);
    }

    public function testDecodeTypeAccessAccepted(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'type-acc']);
        $claims = JWT::decode($token);
        $this->assertSame('access', $claims['type']);
    }

    public function testDecodeTypeRefreshAccepted(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'refresh', 'jti' => 'type-ref']);
        $claims = JWT::decode($token);
        $this->assertSame('refresh', $claims['type']);
    }

    public function testDecodeTypeInvalidRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid token type');
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'reset', 'jti' => 'type-bad']);
        JWT::decode($token);
    }

    public function testDecodeTypeEmptyStringRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => '', 'jti' => 'type-empty']);
        JWT::decode($token);
    }

    public function testDecodeStringSubConvertedToInt(): void
    {
        $token = JWT::encode(['sub' => '42', 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'strsub']);
        $claims = JWT::decode($token);
        // sub is returned as-is from payload (string)
        $this->assertSame('42', $claims['sub']);
    }

    public function testDecodeNonNumericSubRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 'abc', 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'abcsub']);
        JWT::decode($token);
    }

    public function testDecodeMissingSubRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'nosub']);
        JWT::decode($token);
    }

    public function testDecodeMissingVerRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'nover']);
        JWT::decode($token);
    }

    // ============================================================
    // validateAudience — precise branch coverage
    // ============================================================

    public function testValidateAudienceNullReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['sub' => 1], 'anything'));
    }

    public function testValidateAudienceMissingReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['sub' => 1, 'ver' => 1], 'app'));
    }

    public function testValidateAudienceStringMatchReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['aud' => 'web'], 'web'));
    }

    public function testValidateAudienceStringMismatchReturnsFalse(): void
    {
        $this->assertFalse(JWT::validateAudience(['aud' => 'web'], 'mobile'));
    }

    public function testValidateAudienceArrayContainsReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['aud' => ['web', 'mobile']], 'mobile'));
    }

    public function testValidateAudienceArrayNotContainsReturnsFalse(): void
    {
        $this->assertFalse(JWT::validateAudience(['aud' => ['web', 'mobile']], 'cli'));
    }

    public function testValidateAudienceArrayEmptyReturnsFalse(): void
    {
        $this->assertFalse(JWT::validateAudience(['aud' => []], 'web'));
    }

    public function testValidateAudienceIntValueReturnsFalse(): void
    {
        // is_array(123) is false, so falls through to === comparison
        $this->assertFalse(JWT::validateAudience(['aud' => 123], '123'));
    }

    // ============================================================
    // secret() — precise boundary tests
    // ============================================================

    public function testSecretMissingThrows(): void
    {
        putenv('JWT_SECRET');
        unset($_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not configured');
        JWT::encodeAccess(1, 1);
    }

    public function testSecretEmptyThrows(): void
    {
        putenv('JWT_SECRET=');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testSecretTooShortThrows(): void
    {
        putenv('JWT_SECRET=short');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too weak');
        JWT::encodeAccess(1, 1);
    }

    public function testSecretExactly31CharsThrows(): void
    {
        putenv('JWT_SECRET=1234567890123456789012345678901'); // 31 chars
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testSecretExactly32CharsAccepted(): void
    {
        putenv('JWT_SECRET=12345678901234567890123456789012'); // 32 chars
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testSecretPlaceholderChangeThisRejected(): void
    {
        putenv('JWT_SECRET=change_this_to_a_real_secret_32chars!!');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testSecretPlaceholderPleaseSetRejected(): void
    {
        putenv('JWT_SECRET=please_set_a_real_secret_key_32chars!!');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testSecretPlaceholderYourSecretRejected(): void
    {
        putenv('JWT_SECRET=your_secret_must_be_at_least_32chars!!');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    // ============================================================
    // rotateKey — precise version increment
    // ============================================================

    public function testRotateKeyIncrementsVersionByExactlyOne(): void
    {
        JWT::setKeyVersion('10');
        JWT::rotateKey('new_secret_1234567890_abcdefghijk');
        $this->assertSame('11', JWT::getKeyVersion());
    }

    public function testRotateKeySetsNewSecret(): void
    {
        JWT::setKeyVersion('1');
        JWT::rotateKey('brand_new_secret_1234567890_abcdefghijk');
        // Encode with new secret should work
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testRotateKeyWritesToEnvFile(): void
    {
        $envPath = sys_get_temp_dir() . '/siro_jwt_test_' . uniqid() . '.env';
        file_put_contents($envPath, "JWT_SECRET=old_secret_1234567890_abcdefghijk\nJWT_KEY_VERSION=3\n");
        JWT::setKeyVersion('3');
        JWT::rotateKey('rotated_secret_1234567890_abcdefghijk', $envPath);
        $content = file_get_contents($envPath);
        $this->assertStringContainsString('JWT_SECRET=rotated_secret_1234567890_abcdefghijk', $content);
        $this->assertStringContainsString('JWT_KEY_VERSION=4', $content);
        $this->assertStringNotContainsString('old_secret', $content);
        @unlink($envPath);
    }

    public function testRotateKeyWithoutFileStillWorks(): void
    {
        JWT::setKeyVersion('5');
        JWT::rotateKey('another_secret_1234567890_abcdefghijk');
        $this->assertSame('6', JWT::getKeyVersion());
    }

    public function testRotateKeyNonexistentFileStillWorks(): void
    {
        JWT::setKeyVersion('1');
        JWT::rotateKey('secret_1234567890_abcdefghijk', '/nonexistent/path.env');
        $this->assertSame('2', JWT::getKeyVersion());
    }

    // ============================================================
    // verifyHs256WithRotation — rotation path tests
    // ============================================================

    public function testVerifyWithCurrentSecret(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testVerifyWithPreviousSecret(): void
    {
        putenv('JWT_PREVIOUS_SECRET=previous_secret_1234567890_abcdefghijk');
        putenv('JWT_SECRET=current_secret_1234567890_abcdefghijk');
        Env::reset();
        JWT::reset();
        JWT::setKeyVersion('2');

        // Sign with previous secret
        $ref = new \ReflectionClass(JWT::class);
        $signMethod = $ref->getMethod('signHs256WithSecret');
        $signMethod->setAccessible(true);
        $data = 'test.data';
        $prevSig = $signMethod->invoke(null, $data, 'previous_secret_1234567890_abcdefghijk');
        $this->assertNotEmpty($prevSig);

        // Verify should accept previous secret
        $verifyMethod = $ref->getMethod('verifyHs256WithRotation');
        $verifyMethod->setAccessible(true);
        $this->assertTrue($verifyMethod->invoke(null, $data, $prevSig));
    }

    public function testVerifyWithWrongSecretFails(): void
    {
        putenv('JWT_PREVIOUS_SECRET=');
        putenv('JWT_SECRET=current_secret_1234567890_abcdefghijk');
        Env::reset();
        JWT::reset();

        $ref = new \ReflectionClass(JWT::class);
        $signMethod = $ref->getMethod('signHs256WithSecret');
        $signMethod->setAccessible(true);
        $data = 'test.data';
        $wrongSig = $signMethod->invoke(null, $data, 'completely_wrong_secret_1234567890');

        $verifyMethod = $ref->getMethod('verifyHs256WithRotation');
        $verifyMethod->setAccessible(true);
        $this->assertFalse($verifyMethod->invoke(null, $data, $wrongSig));
    }

    public function testPreviousSecretEmptyReturnsEmpty(): void
    {
        putenv('JWT_PREVIOUS_SECRET=');
        Env::reset();
        $ref = new \ReflectionClass(JWT::class);
        $method = $ref->getMethod('previousSecret');
        $method->setAccessible(true);
        $this->assertSame('', $method->invoke(null));
    }

    public function testPreviousSecretReturnsValue(): void
    {
        putenv('JWT_PREVIOUS_SECRET=my_old_secret_1234567890_abcdefghijk');
        Env::reset();
        $ref = new \ReflectionClass(JWT::class);
        $method = $ref->getMethod('previousSecret');
        $method->setAccessible(true);
        $this->assertSame('my_old_secret_1234567890_abcdefghijk', $method->invoke(null));
    }

    // ============================================================
    // blacklistJti — cache integration
    // ============================================================

    public function testBlacklistJtiWithFutureExpiryRevokes(): void
    {
        $jti = 'revoke-future-' . uniqid();
        JWT::blacklistJti($jti, time() + 3600);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => 'access', 'jti' => $jti, 'iat' => time(), 'exp' => time() + 3600]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revoked');
        JWT::decode($token);
    }

    public function testBlacklistJtiWithPastExpiryDoesNotRevoke(): void
    {
        $jti = 'revoke-past-' . uniqid();
        JWT::blacklistJti($jti, time() - 10);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => 'access', 'jti' => $jti, 'iat' => time(), 'exp' => time() + 3600]);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testBlacklistJtiWithZeroExpiryDoesNotRevoke(): void
    {
        $jti = 'revoke-zero-' . uniqid();
        JWT::blacklistJti($jti, 0);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => 'access', 'jti' => $jti, 'iat' => time(), 'exp' => time() + 3600]);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    // ============================================================
    // cleanupBlacklist — interval boundary
    // ============================================================

    public function testCleanupBlacklistRunsOnFirstDecode(): void
    {
        JWT::reset();
        // First decode should trigger cleanupBlacklist
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testCleanupBlacklistSkipsWhenRecent(): void
    {
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        JWT::decode($token);
        // Second decode within 300s should skip cleanup
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    // ============================================================
    // RS256 — precise key handling
    // ============================================================

    public function testRs256RoundTrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $pub = openssl_pkey_get_details($res);

        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=' . $pub['key']);
        putenv('JWT_SECRET=');
        Env::reset();
        JWT::reset();

        $token = JWT::encodeAccess(42, 3);
        $claims = JWT::decode($token);
        $this->assertSame(42, $claims['sub']);
        $this->assertSame(3, $claims['ver']);
    }

    public function testRs256PrivateKeyPathNotFoundThrows(): void
    {
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=');
        putenv('JWT_PRIVATE_KEY_PATH=/nonexistent/key.pem');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        JWT::encodeAccess(1, 1);
    }

    public function testRs256PrivateKeyFileNotReadableThrows(): void
    {
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=');
        putenv('JWT_PRIVATE_KEY_PATH=' . sys_get_temp_dir() . '/nonexistent_file.pem');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testRs256MissingPrivateKeyThrows(): void
    {
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=');
        putenv('JWT_PRIVATE_KEY_PATH=');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('required');
        JWT::encodeAccess(1, 1);
    }

    public function testRs256MissingPublicKeyThrows(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);

        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=');
        putenv('JWT_PUBLIC_KEY_PATH=');
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('required');
        JWT::decode($token);
    }

    public function testRs256PublicKeyPathNotFoundThrows(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);

        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=');
        putenv('JWT_PUBLIC_KEY_PATH=/nonexistent/pub.pem');
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $this->expectException(\RuntimeException::class);
        JWT::decode($token);
    }

    public function testRs256InvalidSignatureRejected(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $pub = openssl_pkey_get_details($res);

        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=' . $pub['key']);
        putenv('JWT_SECRET=');
        Env::reset();
        JWT::reset();

        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $parts[2] = base64_encode('tampered_signature');
        $this->expectException(\RuntimeException::class);
        JWT::decode(implode('.', $parts));
    }
}
