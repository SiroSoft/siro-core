<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * AES-256-CBC encryption with HMAC integrity verification.
 *
 * Provides encrypt/decrypt with automatic key resolution from
 * APP_KEY or JWT_SECRET environment variables.
 *
 * @package Siro\Core
 */

final class Encrypter
{
    private const CIPHER = 'aes-256-cbc';
    private const HMAC_ALGO = 'sha256';

    public static function encrypt(string $data, ?string $key = null): string
    {
        $keys = self::key($key);
        $iv = random_bytes(max(1, openssl_cipher_iv_length(self::CIPHER)));
        $encrypted = openssl_encrypt($data, self::CIPHER, $keys['enc'], OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed.');
        }
        $hmac = hash_hmac(self::HMAC_ALGO, $iv . $encrypted, $keys['auth'], true);
        return base64_encode($hmac . $iv . $encrypted);
    }

    public static function decrypt(string $payload, ?string $key = null): string
    {
        $keys = self::key($key);
        $data = base64_decode($payload, true);
        if ($data === false || strlen($data) < 64) {
            throw new RuntimeException('Invalid encrypted payload.');
        }
        $hmacLength = 32;
        $ivLength = max(1, openssl_cipher_iv_length(self::CIPHER));

        $hmac = substr($data, 0, $hmacLength);
        $iv = substr($data, $hmacLength, $ivLength);
        $encrypted = substr($data, $hmacLength + $ivLength);

        $expected = hash_hmac(self::HMAC_ALGO, $iv . $encrypted, $keys['auth'], true);
        if (!hash_equals($expected, $hmac)) {
            throw new RuntimeException('Invalid HMAC or corrupted data.');
        }

        $decrypted = openssl_decrypt($encrypted, self::CIPHER, $keys['enc'], OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            throw new RuntimeException('Decryption failed.');
        }
        return $decrypted;
    }

    /** @return array{enc: string, auth: string} */
    private static function key(?string $key): array
    {
        $key ??= (string) Env::get('APP_KEY', '');
        if ($key === '') {
            $key = (string) Env::get('JWT_SECRET', '');
        }
        if ($key === '') {
            throw new RuntimeException('Encryption key not configured. Set APP_KEY or JWT_SECRET in .env.');
        }
        $raw = hash('sha256', $key, true);
        // Derive separate keys using HKDF-like expansion
        $encKey = hash_hmac('sha256', 'encryption', $raw, true);
        $authKey = hash_hmac('sha256', 'authentication', $raw, true);
        return ['enc' => $encKey, 'auth' => $authKey];
    }
}
