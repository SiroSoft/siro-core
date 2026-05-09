<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;

final class JWTTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_key_for_unit_tests_32_chars_long!!';
        putenv('JWT_SECRET=' . $_ENV['JWT_SECRET']);
    }

    public function testEncodeAccessReturnsString(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);
    }

    public function testEncodeAccessTokenHasThreeParts(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testDecodeValidAccessToken(): void
    {
        $token = JWT::encodeAccess(42, 2);
        $claims = JWT::decode($token);
        $this->assertIsArray($claims);
        $this->assertSame(42, $claims['sub']);
        $this->assertSame(2, $claims['ver']);
        $this->assertSame('access', $claims['type']);
    }

    public function testDecodeTokenHasIatAndExp(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertArrayHasKey('exp', $claims);
        $this->assertIsInt($claims['iat']);
        $this->assertIsInt($claims['exp']);
    }

    public function testDecodeTokenHasJti(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertArrayHasKey('jti', $claims);
        $this->assertSame(32, strlen($claims['jti']));
    }

    public function testDifferentUsersHaveDifferentTokens(): void
    {
        $token1 = JWT::encodeAccess(1, 1);
        $token2 = JWT::encodeAccess(2, 1);
        $this->assertNotSame($token1, $token2);
    }

    public function testDifferentTokenVersionsDiffer(): void
    {
        $token1 = JWT::encodeAccess(1, 1);
        $token2 = JWT::encodeAccess(1, 2);
        $this->assertNotSame($token1, $token2);
    }

    public function testEncodeRefreshReturnsDifferentToken(): void
    {
        $access = JWT::encodeAccess(1, 1);
        $refresh = JWT::encodeRefresh(1, 1);
        $this->assertNotSame($access, $refresh);
    }

    public function testDecodeRefreshToken(): void
    {
        $token = JWT::encodeRefresh(1, 1, 604800, 'test-jti-12345');
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
        $this->assertSame('refresh', $claims['type']);
        $this->assertSame('test-jti-12345', $claims['jti']);
    }

    public function testRefreshTokenHasLongerTtl(): void
    {
        $access = JWT::encodeAccess(1, 1, 3600);
        $refresh = JWT::encodeRefresh(1, 1, 604800);

        $aClaims = JWT::decode($access);
        $rClaims = JWT::decode($refresh);

        $aTtl = $aClaims['exp'] - $aClaims['iat'];
        $rTtl = $rClaims['exp'] - $rClaims['iat'];

        $this->assertGreaterThan($aTtl, $rTtl);
    }

    public function testRejectsExpiredToken(): void
    {
        $this->expectException(\RuntimeException::class);
        $expired = JWT::encode([
            'sub' => 1, 'ver' => 1,
            'iat' => time() - 7200, 'exp' => time() - 3600,
            'type' => 'access', 'jti' => 'expired-jti',
        ]);
        JWT::decode($expired);
    }

    public function testRejectsTokenFromFuture(): void
    {
        $this->expectException(\RuntimeException::class);
        $future = JWT::encode([
            'sub' => 1, 'ver' => 1,
            'iat' => time() + 3600, 'exp' => time() + 7200,
            'type' => 'access', 'jti' => 'future-jti',
        ]);
        JWT::decode($future);
    }

    public function testRejectsTamperedPayload(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $decoded = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $decoded['sub'] = 999;
        $parts[1] = rtrim(strtr(base64_encode((string) json_encode($decoded)), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $this->expectException(\RuntimeException::class);
        JWT::decode($tampered);
    }

    public function testRejectsMalformedToken(): void
    {
        $this->expectException(\RuntimeException::class);
        JWT::decode('not.a.token');
    }

    public function testRejectsEmptyToken(): void
    {
        $this->expectException(\RuntimeException::class);
        JWT::decode('');
    }

    public function testRejectsTokenWithInvalidSignature(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $parts[2] = str_rot13($parts[2]);
        $tampered = implode('.', $parts);

        $this->expectException(\RuntimeException::class);
        JWT::decode($tampered);
    }

    public function testCustomTtlIsHonored(): void
    {
        $shortTtl = 60;
        $token = JWT::encodeAccess(1, 1, $shortTtl);
        $claims = JWT::decode($token);
        $actualTtl = $claims['exp'] - $claims['iat'];
        $this->assertSame($shortTtl, $actualTtl);
    }

    public function testDecodeTokenWithCustomPayload(): void
    {
        $payload = ['sub' => 5, 'ver' => 1, 'role' => 'admin', 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'jti' => 'custom'];
        $token = JWT::encode($payload);
        $claims = JWT::decode($token);
        $this->assertSame('admin', $claims['role']);
    }

    public function testTokenVersionMismatch(): void
    {
        $token = JWT::encodeAccess(1, 5);
        $claims = JWT::decode($token);
        $this->assertSame(5, $claims['ver']);
    }

    public function testEncodeWithZeroVersionDefaultsToOne(): void
    {
        $token = JWT::encodeAccess(1, 0);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['ver']);
    }

    public function testEachTokenHasUniqueJti(): void
    {
        $t1 = JWT::decode(JWT::encodeAccess(1, 1));
        $t2 = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertNotSame($t1['jti'], $t2['jti']);
    }
}
