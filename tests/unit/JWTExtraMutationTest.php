<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Cache;
use Siro\Core\Env;

/**
 * JWT edge branches: rotation, blacklist, rotateKey, algorithm guards.
 */
final class JWTExtraMutationTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        unset($_ENV['JWT_SECRET'], $_ENV['JWT_PREVIOUS_SECRET'], $_ENV['JWT_ALGORITHM'], $_ENV['JWT_KEY_VERSION']);
        putenv('APP_ENV=testing');
        putenv('JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678');
        putenv('JWT_KEY_VERSION=1');
        JWT::reset();
        $this->envPath = sys_get_temp_dir() . '/siro_env_' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('JWT_SECRET');
        putenv('JWT_PREVIOUS_SECRET');
        putenv('JWT_ALGORITHM');
        putenv('JWT_KEY_VERSION');
        putenv('JWT_PRIVATE_KEY');
        putenv('JWT_PUBLIC_KEY');
        putenv('JWT_PRIVATE_KEY_PATH');
        putenv('JWT_PUBLIC_KEY_PATH');
        Env::reset();
        Cache::reset();
        JWT::reset();
        @unlink($this->envPath);
        parent::tearDown();
    }

    public function testPreviousSecretRotationValidates(): void
    {
        JWT::setKeyVersion('2');
        $token = JWT::encodeAccess(1, 2);
        putenv('JWT_PREVIOUS_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678');
        putenv('JWT_SECRET=this_is_a_different_long_secret_1234567890_ab');
        Env::reset();
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testPreviousSecretEmptyReturnsEmpty(): void
    {
        putenv('JWT_PREVIOUS_SECRET=');
        $secret = (new \ReflectionClass(JWT::class))->getMethod('previousSecret');
        $secret->setAccessible(true);
        $this->assertSame('', $secret->invoke(null));
    }

    public function testBlacklistAndRevoked(): void
    {
        JWT::blacklistJti('revoked-jti-123', time() + 3600);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => JWT::TYPE_ACCESS, 'jti' => 'revoked-jti-123', 'iat' => time(), 'exp' => time() + 3600]);
        $this->expectException(\RuntimeException::class);
        JWT::decode($token);
    }

    public function testBlacklistExpiredNotRevoked(): void
    {
        JWT::blacklistJti('expired-jti-123', time() - 10);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => JWT::TYPE_ACCESS, 'jti' => 'expired-jti-123', 'exp' => time() + 3600]);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testRotateKeyWritesEnvFile(): void
    {
        file_put_contents($this->envPath, "JWT_SECRET=old_secret_value\nJWT_KEY_VERSION=1\n");
        JWT::setKeyVersion('1');
        JWT::rotateKey('new_long_secret_value_1234567890_abcdef', $this->envPath);
        $content = file_get_contents($this->envPath);
        $this->assertStringContainsString('new_long_secret_value_1234567890_abcdef', $content);
        $this->assertStringContainsString('JWT_KEY_VERSION=2', $content);
    }

    public function testRotateKeyMissingSecretInFile(): void
    {
        file_put_contents($this->envPath, "JWT_KEY_VERSION=1\nAPP_ENV=testing\n");
        JWT::setKeyVersion('1');
        JWT::rotateKey('brand_new_secret_1234567890_abcdefghijk', $this->envPath);
        $content = file_get_contents($this->envPath);
        $this->assertStringContainsString('JWT_KEY_VERSION=2', $content);
        $this->assertStringContainsString('APP_ENV=testing', $content);
    }

    public function testRotateKeyMissingVersionInFile(): void
    {
        file_put_contents($this->envPath, "JWT_SECRET=old_secret_value\nAPP_ENV=testing\n");
        JWT::setKeyVersion('3');
        JWT::rotateKey('another_secret_1234567890_abcdefghijk', $this->envPath);
        $content = file_get_contents($this->envPath);
        $this->assertStringContainsString('another_secret_1234567890_abcdefghijk', $content);
        $this->assertStringContainsString('APP_ENV=testing', $content);
    }

    public function testRotateKeyWithOldAndNewSecrets(): void
    {
        file_put_contents($this->envPath, "JWT_SECRET=old_super_long_secret_12345678\nJWT_KEY_VERSION=5\n");
        JWT::setKeyVersion('5');
        JWT::rotateKey('new_super_long_secret_12345678', $this->envPath);
        $content = file_get_contents($this->envPath);
        $this->assertStringNotContainsString('old_super_long_secret', $content);
        $this->assertStringContainsString('new_super_long_secret_12345678', $content);
        $this->assertStringContainsString('JWT_KEY_VERSION=6', $content);
    }

    public function testBlacklistJtiAndCheckIfBlacklisted(): void
    {
        $jti = 'test-jti-' . uniqid();
        JWT::blacklistJti($jti, time() + 3600);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => 'access', 'jti' => $jti, 'iat' => time(), 'exp' => time() + 3600]);
        $this->expectException(\RuntimeException::class);
        JWT::decode($token);
    }

    public function testBlacklistJtiExpiredNotRevoked(): void
    {
        $jti = 'expired-bl-test-' . uniqid();
        JWT::blacklistJti($jti, time() - 10);
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => 'access', 'jti' => $jti, 'iat' => time(), 'exp' => time() + 3600]);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testKeyVersionDefaultsToEnvValue(): void
    {
        putenv('JWT_KEY_VERSION=7');
        Env::reset();
        JWT::reset();
        $this->assertSame('7', JWT::getKeyVersion());
    }

    public function testSetKeyVersionOverridesEnv(): void
    {
        putenv('JWT_KEY_VERSION=3');
        Env::reset();
        JWT::reset();
        JWT::setKeyVersion('99');
        $this->assertSame('99', JWT::getKeyVersion());
    }

    public function testAlgorithmReturnsHs256ByDefault(): void
    {
        putenv('JWT_ALGORITHM');
        unset($_ENV['JWT_ALGORITHM']);
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $header = json_decode(base64_decode(explode('.', $token)[0]), true);
        $this->assertSame('HS256', $header['alg']);
    }

    public function testEncodeAccessStructureIsValid(): void
    {
        $token = JWT::encodeAccess(42, 3);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $header = json_decode(base64_decode($parts[0]), true);
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
        $payload = json_decode(base64_decode($parts[1]), true);
        $this->assertSame(42, $payload['sub']);
        $this->assertSame(3, $payload['ver']);
        $this->assertSame('access', $payload['type']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('jti', $payload);
        $this->assertArrayHasKey('iss', $payload);
    }

    public function testEncodeRefreshStructureIsValid(): void
    {
        $token = JWT::encodeRefresh(10, 2);
        $payload = json_decode(base64_decode(explode('.', $token)[1]), true);
        $this->assertSame(10, $payload['sub']);
        $this->assertSame(2, $payload['ver']);
        $this->assertSame('refresh', $payload['type']);
    }

    public function testDecodeWithMissingTypeThrows(): void
    {
        $token = JWT::encode(['sub' => 1, 'ver' => 1, 'iat' => time(), 'exp' => time() + 3600, 'jti' => 'no-type']);
        $this->expectException(\RuntimeException::class);
        JWT::decode($token);
    }

    public function testUnsupportedAlgorithmThrows(): void
    {
        putenv('JWT_ALGORITHM=HS512');
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testKeyVersionRoundTrip(): void
    {
        JWT::setKeyVersion('5');
        $this->assertSame('5', JWT::getKeyVersion());
    }

    public function testBase64UrlRoundTrip(): void
    {
        $ref = new \ReflectionClass(JWT::class);
        $enc = $ref->getMethod('base64UrlEncode');
        $enc->setAccessible(true);
        $dec = $ref->getMethod('base64UrlDecode');
        $dec->setAccessible(true);
        $value = 'hello+world/with=padding!';
        $this->assertSame($value, $dec->invoke(null, $enc->invoke(null, $value)));
    }

    public function testBase64UrlDecodePadding(): void
    {
        $ref = new \ReflectionClass(JWT::class);
        $dec = $ref->getMethod('base64UrlDecode');
        $dec->setAccessible(true);
        $this->assertSame('ab', $dec->invoke(null, 'YWI'));
    }

    public function testJtiBlacklistedCheck(): void
    {
        JWT::blacklistJti('active-jti-456', time() + 100);
        $ref = new \ReflectionClass(JWT::class);
        $isBlacklisted = $ref->getMethod('isJtiBlacklisted');
        $isBlacklisted->setAccessible(true);
        $this->assertTrue($isBlacklisted->invoke(null, 'active-jti-456'));
        $this->assertFalse($isBlacklisted->invoke(null, 'other-jti'));
    }

    public function testSecretTooWeakThrows(): void
    {
        putenv('JWT_SECRET=short');
        unset($_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testSecretMissingThrows(): void
    {
        putenv('JWT_SECRET');
        unset($_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testRs256RoundTrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $pub = openssl_pkey_get_details($res);
        $publicKey = $pub['key'];

        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=' . $publicKey);
        putenv('JWT_SECRET=');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PUBLIC_KEY'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();

        $token = JWT::encodeAccess(1, 2);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
        $this->assertSame(2, $claims['ver']);
    }

    public function testRs256PrivateKeyPath(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $pub = openssl_pkey_get_details($res);
        $publicKey = $pub['key'];
        $privPath = sys_get_temp_dir() . '/siro_rsa_priv_' . uniqid() . '.pem';
        $pubPath = sys_get_temp_dir() . '/siro_rsa_pub_' . uniqid() . '.pem';
        file_put_contents($privPath, $privateKey);
        file_put_contents($pubPath, $publicKey);

        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY_PATH=' . $privPath);
        putenv('JWT_PUBLIC_KEY_PATH=' . $pubPath);
        putenv('JWT_SECRET=');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY_PATH'], $_ENV['JWT_PUBLIC_KEY_PATH'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();

        $token = JWT::encodeAccess(5, 1);
        $claims = JWT::decode($token);
        $this->assertSame(5, $claims['sub']);
        @unlink($privPath);
        @unlink($pubPath);
    }

    public function testRs256MissingPrivateKeyThrows(): void
    {
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=');
        putenv('JWT_PRIVATE_KEY_PATH=');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PRIVATE_KEY_PATH'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testRs256PrivateKeyPathNotFound(): void
    {
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=');
        putenv('JWT_PRIVATE_KEY_PATH=/nonexistent/key.pem');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PRIVATE_KEY_PATH'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
        JWT::encodeAccess(1, 1);
    }

    public function testRs256InvalidPrivateKeyThrows(): void
    {
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=not-a-valid-key');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $this->expectException(\RuntimeException::class);
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
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PUBLIC_KEY'], $_ENV['JWT_PUBLIC_KEY_PATH'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $this->expectException(\RuntimeException::class);
        JWT::decode($token);
    }

    public function testRs256InvalidSignature(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $pub = openssl_pkey_get_details($res);
        $publicKey = $pub['key'];
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=' . $publicKey);
        putenv('JWT_SECRET=');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PUBLIC_KEY'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $parts[1] = 'eyJzdWIiOjEsInZlciI6MSwidHlwZSI6ImFjY2VzcyJ9.tampered';
        $tampered = implode('.', $parts);
        $this->expectException(\RuntimeException::class);
        JWT::decode($tampered);
    }

    public function testUnsupportedAlgorithmMessageContainsName(): void
    {
        putenv('JWT_ALGORITHM=ES256');
        Env::reset();
        JWT::reset();
        try {
            JWT::encodeAccess(1, 1);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ES256', $e->getMessage());
            $this->assertStringContainsString('Unsupported', $e->getMessage());
        }
    }

    public function testInvalidPayloadHeaderNotArray(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $parts[1] = base64_encode(json_encode('not-an-array'));
        $tampered = implode('.', $parts);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid token payload');
        JWT::decode($tampered);
    }

    public function testInvalidPayloadPayloadNotArray(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $header = json_decode($parts[1], true);
        $parts[1] = base64_encode(json_encode($header)) . '.' . base64_encode('not-an-array');
        $tampered = implode('.', $parts);
        $this->expectException(\RuntimeException::class);
        JWT::decode($tampered);
    }

    public function testAlgorithmMismatchMessageContainsAlgorithms(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $header = json_decode(base64_decode($parts[0]), true);
        $header['alg'] = 'RS256';
        $parts[0] = base64_encode(json_encode($header));
        $tampered = implode('.', $parts);
        try {
            JWT::decode($tampered);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Algorithm mismatch', $e->getMessage());
        }
    }

    public function testRs256EncodeUsesRs256Signature(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $pub = openssl_pkey_get_details($res);
        $publicKey = $pub['key'];

        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=' . $publicKey);
        putenv('JWT_SECRET=');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PUBLIC_KEY'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();

        $token = JWT::encodeAccess(1, 1);
        $this->assertStringContainsString('.', $token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testDecodeWithNonArrayHeader(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $parts[0] = base64_encode('"not-a-header"');
        $tampered = implode('.', $parts);
        $this->expectException(\RuntimeException::class);
        JWT::decode($tampered);
    }

    public function testDecodeWithEmptyPayload(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $parts[1] = base64_encode('{}');
        $tampered = implode('.', $parts);
        $this->expectException(\RuntimeException::class);
        JWT::decode($tampered);
    }

    public function testSecretTrimmedFromEnv(): void
    {
        putenv('JWT_SECRET=  this_is_a_sufficiently_long_jwt_secret_with_spaces  ');
        Env::reset();
        JWT::reset();
        $token = JWT::encodeAccess(1, 1);
        putenv('JWT_SECRET=  this_is_a_sufficiently_long_jwt_secret_with_spaces  ');
        Env::reset();
        JWT::reset();
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testBlacklistCleanupIntervalConstant(): void
    {
        $ref = new \ReflectionClass(JWT::class);
        $const = $ref->getConstant('BLACKLIST_CLEANUP_INTERVAL');
        $this->assertIsInt($const);
        $this->assertGreaterThan(0, $const);
    }

    public function testDecodeWithNonStringAlgorithmHeader(): void
    {
        $token = JWT::encodeAccess(1, 1);
        $parts = explode('.', $token);
        $header = json_decode($parts[0], true);
        $header['alg'] = 123;
        $parts[0] = base64_encode(json_encode($header));
        $tampered = implode('.', $parts);
        $this->expectException(\RuntimeException::class);
        JWT::decode($tampered);
    }
}