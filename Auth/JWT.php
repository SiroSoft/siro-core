<?php

declare(strict_types=1);

namespace Siro\Core\Auth;

use RuntimeException;
use Siro\Core\Env;

final class JWT
{
    public static function encode(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $secret = self::secret();

        $segments = [
            self::base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
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

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Invalid token payload.');
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Unsupported token algorithm.');
        }

        $secret = self::secret();
        $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);

        if (!hash_equals($expected, $signature)) {
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
            throw new RuntimeException('Invalid token subject.');
        }

        $ver = isset($payload['ver']) ? (int) $payload['ver'] : 0;
        if ($ver <= 0) {
            throw new RuntimeException('Invalid token version.');
        }

        return $payload;
    }

    private static function secret(): string
    {
        $secret = (string) Env::get('JWT_SECRET', '');
        if ($secret === '') {
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
