<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Signed URL generator and validator.
 *
 * Creates HMAC-signed URLs with optional expiration for secure
 * one-time links (email verification, unsubscribe, etc.).
 * Uses APP_KEY or JWT_SECRET as signing key.
 *
 * @package Siro\Core
 */

final class URL
{
    public static function signed(string $route, array $params = [], ?int $expires = null): string
    {
        $secret = self::secret();
        $data = ['route' => $route, 'params' => $params];
        if ($expires !== null) {
            $data['expires'] = time() + $expires;
        }
        $payload = base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));
        $signature = hash_hmac('sha256', $payload, $secret);
        $query = http_build_query(['payload' => $payload, 'signature' => $signature]);
        $base = defined('APP_URL') ? APP_URL : 'http://localhost:8080';
        return rtrim($base, '/') . '/' . ltrim($route, '/') . '?' . $query;
    }

    public static function validate(string $payload, string $signature, bool $throw = false): ?array
    {
        $secret = self::secret();
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            if ($throw) throw new RuntimeException('Invalid signature.');
            return null;
        }
        $data = json_decode(base64_decode($payload), true);
        if (!is_array($data)) {
            if ($throw) throw new RuntimeException('Invalid payload.');
            return null;
        }
        if (isset($data['expires']) && $data['expires'] < time()) {
            if ($throw) throw new RuntimeException('Signed URL has expired.');
            return null;
        }
        return $data;
    }

    public static function validateRequest(Request $request, bool $throw = false): ?array
    {
        $payload = (string) $request->query('payload', '');
        $signature = (string) $request->query('signature', '');
        if ($payload === '' || $signature === '') {
            if ($throw) throw new RuntimeException('Missing signature parameters.');
            return null;
        }
        return self::validate($payload, $signature, $throw);
    }

    private static function secret(): string
    {
        $key = Env::get('APP_KEY', '');
        if ($key === '') {
            $key = Env::get('JWT_SECRET', '');
        }
        if ($key === '') {
            throw new RuntimeException('APP_KEY or JWT_SECRET required for signed URLs.');
        }
        return $key;
    }
}
