<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Env;

/**
 * Strong-assertion tests targeting mutations on Auth\JWT.
 * Focuses on default TTLs, issuer handling, audience, algorithm normalization,
 * and time-boundary claims (nbf/iat/exp edges).
 */
final class JWTMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('JWT_ALGORITHM');
        putenv('APP_URL');
        putenv('JWT_SECRET');
        putenv('JWT_KEY_VERSION');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['APP_URL'], $_ENV['JWT_SECRET'], $_ENV['JWT_KEY_VERSION']);
        JWT::reset();
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_key_for_unit_tests_32_chars_long!!';
        putenv('JWT_SECRET=' . $_ENV['JWT_SECRET']);
    }

    protected function tearDown(): void
    {
        putenv('JWT_ALGORITHM');
        putenv('APP_URL');
        putenv('JWT_SECRET');
        putenv('JWT_KEY_VERSION');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['APP_URL'], $_ENV['JWT_SECRET'], $_ENV['JWT_KEY_VERSION']);
        JWT::reset();
        parent::tearDown();
    }

    public function testAccessDefaultTtlIs3600(): void
    {
        $claims = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function testRefreshDefaultTtlIs604800(): void
    {
        $claims = JWT::decode(JWT::encodeRefresh(1, 1));
        $this->assertSame(604800, $claims['exp'] - $claims['iat']);
    }

    public function testIssFallsBackToSiroApi(): void
    {
        $claims = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertSame('siro-api', $claims['iss']);
    }

    public function testIssTrimsTrailingSlash(): void
    {
        $_ENV['APP_URL'] = 'https://example.com/';
        putenv('APP_URL=https://example.com/');
        $claims = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertSame('https://example.com', $claims['iss']);
    }

    public function testIssUsesAppUrl(): void
    {
        $_ENV['APP_URL'] = 'https://api.example.test';
        putenv('APP_URL=https://api.example.test');
        $claims = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertSame('https://api.example.test', $claims['iss']);
    }

    public function testEncodeAccessWithAudience(): void
    {
        $claims = JWT::decode(JWT::encodeAccess(1, 1, 3600, 'mobile-app'));
        $this->assertSame('mobile-app', $claims['aud']);
    }

    public function testEncodeRefreshWithAudience(): void
    {
        $claims = JWT::decode(JWT::encodeRefresh(1, 1, 604800, 'jti-refresh-aud', 'admin-panel'));
        $this->assertSame('admin-panel', $claims['aud']);
    }

    public function testLowercaseAlgorithmIsNormalized(): void
    {
        $_ENV['JWT_ALGORITHM'] = 'hs256';
        putenv('JWT_ALGORITHM=hs256');
        JWT::reset();
        $claims = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertSame(1, $claims['sub']);
    }

    public function testUppercaseAlgorithmStored(): void
    {
        $_ENV['JWT_ALGORITHM'] = 'HS256';
        putenv('JWT_ALGORITHM=HS256');
        JWT::reset();
        $claims = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertSame(1, $claims['sub']);
    }

    public function testEncodeAccessVersionZeroDefaultsToOne(): void
    {
        $claims = JWT::decode(JWT::encodeAccess(1, 0));
        $this->assertSame(1, $claims['ver']);
    }

    public function testEncodeRefreshVersionZeroDefaultsToOne(): void
    {
        $claims = JWT::decode(JWT::encodeRefresh(1, 0));
        $this->assertSame(1, $claims['ver']);
    }

    public function testDecodeRejectsNegativeSub(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => -1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'neg-sub']);
        JWT::decode($token);
    }

    public function testDecodeRejectsZeroVer(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 0, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'zero-ver']);
        JWT::decode($token);
    }

    public function testDecodeRejectsMissingType(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'jti' => 'no-type']);
        JWT::decode($token);
    }

    public function testDecodeNbfInPastIsValid(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'nbf' => time() - 60, 'jti' => 'nbf-past']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testDecodeNbfInFutureRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'nbf' => time() + 120, 'jti' => 'nbf-future']);
        JWT::decode($token);
    }

    public function testDecodeZeroNbfAccepted(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'nbf' => 0, 'jti' => 'nbf-zero']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testIatBoundary60SecondsAccepted(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time() + 60, 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'iat-60']);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testIatBeyond60SecondsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time() + 120, 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'iat-120']);
        JWT::decode($token);
    }

    public function testExpExactlyNowIsExpired(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time() - 100, 'exp' => time() - 1, 'type' => 'access', 'jti' => 'exp-now']);
        JWT::decode($token);
    }

    public function testStringSubAccepted(): void
    {
        $token = JWT::encode(['sub' => '42', 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'str-sub']);
        $claims = JWT::decode($token);
        $this->assertSame('42', $claims['sub']);
    }

    public function testKeyVersionRoundTrip(): void
    {
        JWT::setKeyVersion('3');
        $this->assertSame('3', JWT::getKeyVersion());
        JWT::setKeyVersion('7');
        $this->assertSame('7', JWT::getKeyVersion());
    }

    public function testGetKeyVersionDefaultsToOne(): void
    {
        $this->assertSame('1', JWT::getKeyVersion());
    }

    public function testValidateAudienceArray(): void
    {
        $this->assertTrue(JWT::validateAudience(['aud' => ['a', 'b']], 'b'));
        $this->assertFalse(JWT::validateAudience(['aud' => ['a', 'b']], 'c'));
    }

    public function testValidateAudienceNullReturnsTrue(): void
    {
        $this->assertTrue(JWT::validateAudience(['sub' => 1], 'anything'));
    }

    public function testValidateAudienceSingleString(): void
    {
        $this->assertTrue(JWT::validateAudience(['aud' => 'app'], 'app'));
        $this->assertFalse(JWT::validateAudience(['aud' => 'app'], 'other'));
    }

    public function testMalformedBase64SegmentRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $parts[1] = '!!!not-base64!!!';
        JWT::decode(implode('.', $parts));
    }

    public function testAlgorithmMismatchRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $header['alg'] = 'RS256';
        $parts[0] = rtrim(strtr(base64_encode((string) json_encode($header)), '+/', '-_'), '=');
        JWT::decode(implode('.', $parts));
    }
}
