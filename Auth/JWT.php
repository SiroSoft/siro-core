<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

use RuntimeException;
use Siro\Core\Cache;
use Siro\Core\Env;
use Siro\Core\Logger;

final class JWT
{
    public const TYPE_ACCESS = 'access';
    public const TYPE_REFRESH = 'refresh';
    public const ALG_HS256 = 'HS256';
    public const ALG_RS256 = 'RS256';

    private static ?string $keyVersion = null;
    private static ?string $algorithm = null;
    private static int $lastBlacklistCleanup = 0;
    private const BLACKLIST_CLEANUP_INTERVAL = 300;

    private static function cleanupBlacklist(): void
    {
        if (time() - self::$lastBlacklistCleanup > self::BLACKLIST_CLEANUP_INTERVAL) {
            self::$lastBlacklistCleanup = time();
            // Actual cleanup happens lazily via Cache TTL expiration
        }
    }

    public static function reset(): void
    {
        self::$keyVersion = null;
        self::$algorithm = null;
        self::$lastBlacklistCleanup = 0;
    }

    private const ALLOWED_ALGORITHMS = [self::ALG_HS256, self::ALG_RS256];

    private static function algorithm(): string
    {
        if (self::$algorithm !== null) {
            return self::$algorithm;
        }
        $alg = strtoupper((string) Env::get('JWT_ALGORITHM', self::ALG_HS256));
        if (!in_array($alg, self::ALLOWED_ALGORITHMS, true)) {
            throw new RuntimeException('Unsupported JWT algorithm: ' . $alg . '. Supported: ' . implode(', ', self::ALLOWED_ALGORITHMS) . '.');
        }
        self::$algorithm = $alg;
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
        $payload['iss'] = rtrim((string) Env::get('APP_URL', ''), '/') ?: 'siro-api';
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
        $payload['iss'] = rtrim((string) Env::get('APP_URL', ''), '/') ?: 'siro-api';
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

        $headerAlg = is_string($header['alg'] ?? null) ? $header['alg'] : '';
        $configuredAlg = self::algorithm();
        if ($headerAlg !== $configuredAlg) {
            throw new RuntimeException('Algorithm mismatch: header declares ' . $headerAlg . ' but server expects ' . $configuredAlg);
        }
        if (!in_array($configuredAlg, [self::ALG_HS256, self::ALG_RS256], true)) {
            throw new RuntimeException('Unsupported token algorithm: ' . $configuredAlg);
        }

        // Validate token type to prevent refresh token reuse as access token
        $tokenType = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        if (!in_array($tokenType, [self::TYPE_ACCESS, self::TYPE_REFRESH], true)) {
            throw new RuntimeException('Invalid token type.');
        }

        $data = $headerB64 . '.' . $payloadB64;
        $valid = match ($configuredAlg) {
            self::ALG_RS256 => self::verifyRs256($data, $signature),
            self::ALG_HS256 => self::verifyHs256($data, $signature),
        };

        if (!$valid) {
            throw new RuntimeException('Invalid token signature.');
        }

        $now = time();
        $exp = is_numeric($payload['exp'] ?? null) ? (int) $payload['exp'] : 0;
        if ($exp <= 0 || $exp < $now) {
            throw new RuntimeException('Token expired.');
        }

        $iat = is_numeric($payload['iat'] ?? null) ? (int) $payload['iat'] : 0;
        if ($iat > $now + 60) {
            throw new RuntimeException('Token issued in the future.');
        }

        $nbf = is_numeric($payload['nbf'] ?? null) ? (int) $payload['nbf'] : 0;
        if ($nbf > 0 && $nbf > $now) {
            throw new RuntimeException('Token is not yet valid (nbf).');
        }

        $sub = is_numeric($payload['sub'] ?? null) ? (int) $payload['sub'] : 0;
        if ($sub <= 0) {
            throw new RuntimeException('JWT token missing required "sub" claim (user ID). Token may be malformed or tampered.');
        }

        $ver = is_numeric($payload['ver'] ?? null) ? (int) $payload['ver'] : 0;
        if ($ver <= 0) {
            throw new RuntimeException('JWT token missing required "ver" claim (token version). Token may be from an incompatible system.');
        }

        self::cleanupBlacklist();

        if (isset($payload['jti']) && is_string($payload['jti']) && self::isJtiBlacklisted($payload['jti'])) {
            throw new RuntimeException('Token has been revoked.');
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
                $realPath = realpath($path);
                if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
                    throw new RuntimeException('JWT_PRIVATE_KEY_PATH file not found or unreadable: ' . $path);
                }
                $privateKey = (string) file_get_contents($realPath);
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

        if (!$result || !is_string($signature)) {
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
                $realPath = realpath($path);
                if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
                    throw new RuntimeException('JWT_PUBLIC_KEY_PATH file not found or unreadable: ' . $path);
                }
                $publicKey = (string) file_get_contents($realPath);
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

    public static function rotateKey(string $newSecret, string $envPath = ''): void
    {
        $newVersion = (int) self::getKeyVersion() + 1;
        putenv("JWT_KEY_VERSION={$newVersion}");
        putenv("JWT_SECRET={$newSecret}");
        Env::reset();
        self::$keyVersion = (string) $newVersion;
        if ($envPath !== '' && is_file($envPath) && is_writable($envPath)) {
            $content = (string) file_get_contents($envPath);
            $content = (string) preg_replace('/^JWT_SECRET=.*$/m', 'JWT_SECRET=' . $newSecret, $content);
            $content = (string) preg_replace('/^JWT_KEY_VERSION=.*$/m', 'JWT_KEY_VERSION=' . $newVersion, $content);
            file_put_contents($envPath, $content, LOCK_EX);
        }
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
            $prevVersion = (int) self::getKeyVersion() - 1;
            if ($prevVersion > 0) {
                return true;
            }
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

    public static function blacklistJti(string $jti, int $expiresAt): void
    {
        Cache::set('jti_blacklist:' . $jti, $expiresAt, max(1, $expiresAt - time()));
    }

    private static function isJtiBlacklisted(string $jti): bool
    {
        $cached = Cache::get('jti_blacklist:' . $jti);
        return is_numeric($cached) && (int) $cached > time();
    }
}
