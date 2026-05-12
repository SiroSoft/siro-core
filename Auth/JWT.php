<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

use RuntimeException;
use Siro\Core\Env;

final class JWT
{
    public const TYPE_ACCESS = 'access';
    public const TYPE_REFRESH = 'refresh';
    public const ALG_HS256 = 'HS256';
    public const ALG_RS256 = 'RS256';

    private static ?string $keyVersion = null;

    private static function algorithm(): string
    {
        $alg = strtoupper((string) Env::get('JWT_ALGORITHM', self::ALG_HS256));
        if (!in_array($alg, [self::ALG_HS256, self::ALG_RS256], true)) {
            throw new RuntimeException('Unsupported JWT algorithm: ' . $alg . '. Supported: HS256, RS256.');
        }
        return $alg;
    }

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        $alg = self::algorithm();
        $header = ['alg' => $alg, 'typ' => 'JWT'];

        $segments = [
            self::base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        $signature = match ($alg) {
            self::ALG_RS256 => self::signRs256(implode('.', $segments)),
            default => self::signHs256(implode('.', $segments)),
        };
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public static function encodeAccess(int $userId, int $tokenVersion, int $ttl = 3600, ?string $audience = null): string
    {
        $now = time();
        $payload = [
            'sub' => $userId,
            'ver' => max(1, $tokenVersion),
            'iat' => $now,
            'exp' => $now + $ttl,
            'type' => self::TYPE_ACCESS,
            'jti' => bin2hex(random_bytes(16)),
        ];
        if ($audience !== null) {
            $payload['aud'] = $audience;
        }
        return self::encode($payload);
    }

    public static function encodeRefresh(int $userId, int $tokenVersion, int $ttl = 604800, ?string $jti = null, ?string $audience = null): string
    {
        $now = time();
        $payload = [
            'sub' => $userId,
            'ver' => max(1, $tokenVersion),
            'iat' => $now,
            'exp' => $now + $ttl,
            'type' => self::TYPE_REFRESH,
            'jti' => $jti ?? bin2hex(random_bytes(16)),
        ];
        if ($audience !== null) {
            $payload['aud'] = $audience;
        }
        return self::encode($payload);
    }

    /** @param array<string, mixed> $payload */
    public static function validateAudience(array $payload, string $audience): bool
    {
        $tokenAudience = $payload['aud'] ?? null;
        if ($tokenAudience === null) {
            return true;
        }
        if (is_array($tokenAudience)) {
            return in_array($audience, $tokenAudience, true);
        }
        return $tokenAudience === $audience;
    }

    /** @return array<string, mixed> */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token structure.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $headerJson = self::base64UrlDecode($headerB64);
        $payloadJson = self::base64UrlDecode($payloadB64);
        $signature = self::base64UrlDecode($signatureB64);

        /** @var array<string, mixed>|null $header */
        $header = json_decode($headerJson, true);
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($payloadJson, true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Invalid token payload.');
        }

        // Always use server-configured algorithm, never trust the token's alg header
        $alg = self::algorithm();
        if (!in_array($alg, [self::ALG_HS256, self::ALG_RS256], true)) {
            throw new RuntimeException('Unsupported token algorithm: ' . $alg);
        }

        $data = $headerB64 . '.' . $payloadB64;
        $valid = match ($alg) {
            self::ALG_RS256 => self::verifyRs256($data, $signature),
            self::ALG_HS256 => self::verifyHs256($data, $signature),
        };

        if (!$valid) {
            throw new RuntimeException('Invalid token signature.');
        }

        $now = time();
        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($exp <= 0 || $exp < $now) {
            throw new RuntimeException('Token expired.');
        }

        $iat = isset($payload['iat']) ? (int) $payload['iat'] : 0;
        if ($iat > $now + 60) {
            throw new RuntimeException('Token issued in the future.');
        }

        $sub = isset($payload['sub']) ? (int) $payload['sub'] : 0;
        if ($sub <= 0) {
            throw new RuntimeException('JWT token missing required "sub" claim (user ID). Token may be malformed or tampered.');
        }

        $ver = isset($payload['ver']) ? (int) $payload['ver'] : 0;
        if ($ver <= 0) {
            throw new RuntimeException('JWT token missing required "ver" claim (token version). Token may be from an incompatible system.');
        }

        return $payload;
    }

    private static function signHs256(string $data): string
    {
        return hash_hmac('sha256', $data, self::secret(), true);
    }

    private static function verifyHs256(string $data, string $signature): bool
    {
        return self::verifyHs256WithRotation($data, $signature);
    }

    private static function signRs256(string $data): string
    {
        $privateKey = (string) Env::get('JWT_PRIVATE_KEY', '');
        if ($privateKey === '') {
            $path = (string) Env::get('JWT_PRIVATE_KEY_PATH', '');
            if ($path !== '') {
                $privateKey = (string) file_get_contents($path);
            }
        }
        if ($privateKey === '') {
            throw new RuntimeException('JWT_PRIVATE_KEY or JWT_PRIVATE_KEY_PATH is required for RS256.');
        }

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException('Invalid RSA private key.');
        }

        $signature = '';
        $result = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        openssl_free_key($key);

        if (!$result) {
            throw new RuntimeException('Failed to sign token with RS256.');
        }

        return $signature;
    }

    private static function verifyRs256(string $data, string $signature): bool
    {
        $publicKey = (string) Env::get('JWT_PUBLIC_KEY', '');
        if ($publicKey === '') {
            $path = (string) Env::get('JWT_PUBLIC_KEY_PATH', '');
            if ($path !== '') {
                $publicKey = (string) file_get_contents($path);
            }
        }
        if ($publicKey === '') {
            throw new RuntimeException('JWT_PUBLIC_KEY or JWT_PUBLIC_KEY_PATH is required for RS256.');
        }

        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            throw new RuntimeException('Invalid RSA public key.');
        }

        $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);
        openssl_free_key($key);

        return $result === 1;
    }

    private static function secret(): string
    {
        $secret = (string) Env::get('JWT_SECRET', '');
        if ($secret === '') {
            if (self::algorithm() !== self::ALG_HS256) {
                return '';
            }
            throw new RuntimeException('JWT_SECRET is not configured.');
        }

        $lowerSecret = strtolower($secret);
        $looksLikePlaceholder = str_contains($lowerSecret, 'change_this')
            || str_contains($lowerSecret, 'please_set')
            || str_contains($lowerSecret, 'your_secret');

        if ($looksLikePlaceholder || strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET is too weak. Use at least 32 characters.');
        }

        return $secret;
    }

    public static function getKeyVersion(): string
    {
        if (self::$keyVersion !== null) {
            return self::$keyVersion;
        }
        return (string) Env::get('JWT_KEY_VERSION', '1');
    }

    public static function setKeyVersion(string $version): void
    {
        self::$keyVersion = $version;
    }

    public static function rotateKey(string $newSecret): void
    {
        $newVersion = (int) self::getKeyVersion() + 1;
        putenv("JWT_KEY_VERSION={$newVersion}");
        putenv("JWT_SECRET={$newSecret}");
        self::$keyVersion = (string) $newVersion;
    }

    private static function previousSecret(): string
    {
        $secret = (string) Env::get('JWT_PREVIOUS_SECRET', '');
        if ($secret === '') {
            return '';
        }
        return $secret;
    }

    private static function verifyHs256WithRotation(string $data, string $signature): bool
    {
        $currentSecret = self::secret();

        if (hash_equals(self::signHs256WithSecret($data, $currentSecret), $signature)) {
            return true;
        }

        $prevSecret = self::previousSecret();
        if ($prevSecret !== '' && hash_equals(self::signHs256WithSecret($data, $prevSecret), $signature)) {
            return true;
        }

        return false;
    }

    private static function signHs256WithSecret(string $data, string $secret): string
    {
        return hash_hmac('sha256', $data, $secret, true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 token segment.');
        }

        return $decoded;
    }
}
