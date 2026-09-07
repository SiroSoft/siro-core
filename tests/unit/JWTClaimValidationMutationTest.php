<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Cache;
use Siro\Core\Env;

/**
 * Targets the surviving Auth\JWT mutants that the existing suites miss:
 *
 *  - Strict numeric-string evaluation of the nbf / iat claims in decode()
 *    (the Ternary mutants that collapse a numeric nbf/iat to 0);
 *  - JWT_PUBLIC_KEY_PATH resolution failure messaging in verifyRs256();
 *  - Invalid RSA public key material in verifyRs256().
 *
 * Tokens are forged directly (same base64url + HMAC construction as JWT::encode)
 * so that claim payloads can carry raw strings — impossible through encodeAccess(),
 * which casts every claim to int.
 */
final class JWTClaimValidationMutationTest extends TestCase
{
    private const SECRET = 'claim_validation_secret_key_used_for_tests_32+';

    private string $envPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        foreach (['JWT_SECRET', 'JWT_PREVIOUS_SECRET', 'JWT_ALGORITHM', 'JWT_KEY_VERSION',
                  'JWT_PUBLIC_KEY', 'JWT_PUBLIC_KEY_PATH', 'JWT_PRIVATE_KEY', 'JWT_PRIVATE_KEY_PATH', 'APP_URL'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
        JWT::reset();
        Cache::reset();
        $_ENV['JWT_SECRET'] = self::SECRET;
        $this->envPath = sys_get_temp_dir() . '/siro_jwt_claim_' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        foreach (['JWT_SECRET', 'JWT_PREVIOUS_SECRET', 'JWT_ALGORITHM', 'JWT_KEY_VERSION',
                  'JWT_PUBLIC_KEY', 'JWT_PUBLIC_KEY_PATH', 'JWT_PRIVATE_KEY', 'JWT_PRIVATE_KEY_PATH', 'APP_URL'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
        Env::reset();
        Cache::reset();
        JWT::reset();
        if ($this->envPath !== '' && is_file($this->envPath)) {
            @unlink($this->envPath);
        }
        parent::tearDown();
    }

    /**
     * Forge a properly signed HS256 token whose payload claims are used verbatim,
     * allowing string-valued nbf/iat/exp that encodeAccess() can never produce.
     *
     * @param array<string, mixed> $claims
     */
    private static function forgeToken(array $claims, ?string $secret = null): string
    {
        $secret ??= self::SECRET;
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $header = $b64((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $payload = $b64((string) json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig = $b64(hash_hmac('sha256', $header . '.' . $payload, $secret, true));

        return $header . '.' . $payload . '.' . $sig;
    }

    private static function validBaseClaims(): array
    {
        return [
            'sub' => 7,
            'ver' => 1,
            'iat' => time(),
            'exp' => time() + 3600,
            'type' => JWT::TYPE_ACCESS,
            'jti' => 'claim-validation-jti',
        ];
    }

    public function testNumericStringNbfInFutureIsRejected(): void
    {
        $claims = self::validBaseClaims();
        $claims['nbf'] = (string) (time() + 600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not yet valid');
        JWT::decode(self::forgeToken($claims));
    }

    public function testNumericStringNbfZeroIsAccepted(): void
    {
        $claims = self::validBaseClaims();
        $claims['nbf'] = '0';

        $payload = JWT::decode(self::forgeToken($claims));
        $this->assertSame(7, $payload['sub']);
    }

    public function testNonNumericStringNbfIsIgnored(): void
    {
        $claims = self::validBaseClaims();
        $claims['nbf'] = 'not-a-timestamp';

        $payload = JWT::decode(self::forgeToken($claims));
        $this->assertSame(7, $payload['sub']);
    }

    public function testFloatStringNbfIsEvaluatedNumerically(): void
    {
        // is_numeric('1.5') is true, so the claim participates in the nbf check
        // (1.5 > 0 but 1.5 <= now) instead of being treated as absent.
        $claims = self::validBaseClaims();
        $claims['nbf'] = '1.5';

        $payload = JWT::decode(self::forgeToken($claims));
        $this->assertSame(7, $payload['sub']);
    }

    public function testNumericStringIatFarInFutureIsRejected(): void
    {
        $claims = self::validBaseClaims();
        $claims['iat'] = (string) (time() + 3600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('issued in the future');
        JWT::decode(self::forgeToken($claims));
    }

    public function testRs256PublicKeyPathNotFoundReportsPath(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY_PATH=/nonexistent/public.pem');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PUBLIC_KEY'], $_ENV['JWT_PUBLIC_KEY_PATH'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();

        $token = JWT::encodeAccess(1, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_PUBLIC_KEY_PATH file not found or unreadable: /nonexistent/public.pem');
        JWT::decode($token);
    }

    public function testRs256PublicKeyPathRoundTrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        $publicKey = (string) (openssl_pkey_get_details($res)['key'] ?? '');

        $path = $this->envPath;
        file_put_contents($path, $publicKey);

        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=');
        putenv('JWT_PUBLIC_KEY_PATH=' . $path);
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PUBLIC_KEY'], $_ENV['JWT_PUBLIC_KEY_PATH'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();

        $payload = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertSame(1, $payload['sub']);
    }

    public function testRs256GarbagePublicKeyMaterialThrows(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl not available');
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        putenv('JWT_ALGORITHM=RS256');
        putenv('JWT_PRIVATE_KEY=' . $privateKey);
        putenv('JWT_PUBLIC_KEY=this-is-not-pem-material');
        unset($_ENV['JWT_ALGORITHM'], $_ENV['JWT_PRIVATE_KEY'], $_ENV['JWT_PUBLIC_KEY'], $_ENV['JWT_PUBLIC_KEY_PATH'], $_ENV['JWT_SECRET']);
        Env::reset();
        JWT::reset();

        $token = JWT::encodeAccess(1, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid RSA public key.');
        JWT::decode($token);
    }
}
