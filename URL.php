<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

final class URL
{
    /** @param array<string, mixed> $params */
    public static function signed(string $route, array $params = [], ?int $expires = null): string
    {
        $secret = self::secret();
        $data = ['route' => $route, 'params' => $params];
        if ($expires !== null) {
            $data['expires'] = time() + $expires;
        }
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        $payload = base64_encode($encoded !== false ? $encoded : '{}');
        $signature = hash_hmac('sha256', $payload, $secret);
        $query = http_build_query(['payload' => $payload, 'signature' => $signature]);
        $base = defined('APP_URL') && is_string(APP_URL) && APP_URL !== '' ? APP_URL : (string) Env::get('APP_URL', 'http://localhost:8080');
        if (filter_var($base, FILTER_VALIDATE_URL) === false) {
            $base = 'http://localhost:8080';
        }
        return rtrim($base, '/') . '/' . ltrim($route, '/') . '?' . $query;
    }

    /** @return array<string, mixed>|null */
    public static function validate(string $payload, string $signature, bool $throw = false): ?array
    {
        $secret = self::secret();
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            if ($throw) throw new RuntimeException('Invalid signature.');
            return null;
        }
        /** @var array<string, mixed>|null $data */
        $data = json_decode(base64_decode($payload), true);
        if (!is_array($data)) {
            if ($throw) throw new RuntimeException('Invalid payload.');
            return null;
        }
        if (isset($data['expires']) && is_numeric($data['expires']) && (int) $data['expires'] < time()) {
            if ($throw) throw new RuntimeException('Signed URL has expired.');
            return null;
        }
        return $data;
    }

    /** @return array<string, mixed>|null */
    public static function validateRequest(Request $request, bool $throw = false): ?array
    {
        $payload = $request->queryString('payload');
        $signature = $request->queryString('signature');
        if ($payload === '' || $signature === '') {
            if ($throw) throw new RuntimeException('Missing signature parameters.');
            return null;
        }
        return self::validate($payload, $signature, $throw);
    }

    private static function secret(): string
    {
        $key = Env::get('APP_KEY', '') ?? '';
        if ($key === '') {
            $key = Env::get('JWT_SECRET', '') ?? '';
        }
        if ($key === '') {
            throw new RuntimeException('APP_KEY or JWT_SECRET required for signed URLs.');
        }
        return $key;
    }
}
