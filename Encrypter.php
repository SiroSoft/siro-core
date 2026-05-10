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
        $key = self::key($key);
        $iv = random_bytes(max(1, openssl_cipher_iv_length(self::CIPHER)));
        $encrypted = openssl_encrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed.');
        }
        $hmac = hash_hmac(self::HMAC_ALGO, $iv . $encrypted, $key, true);
        return base64_encode($hmac . $iv . $encrypted);
    }

    public static function decrypt(string $payload, ?string $key = null): string
    {
        $key = self::key($key);
        $data = base64_decode($payload, true);
        if ($data === false || strlen($data) < 64) {
            throw new RuntimeException('Invalid encrypted payload.');
        }
        $hmac = substr($data, 0, 32);
        $iv = substr($data, 32, 16);
        $encrypted = substr($data, 48);
        $expected = hash_hmac(self::HMAC_ALGO, $iv . $encrypted, $key, true);
        if (!hash_equals($expected, $hmac)) {
            throw new RuntimeException('Invalid HMAC or corrupted data.');
        }
        $decrypted = openssl_decrypt($encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            throw new RuntimeException('Decryption failed.');
        }
        return $decrypted;
    }

    private static function key(?string $key): string
    {
        $key ??= Env::get('APP_KEY', '');
        if ($key === '') {
            $key = Env::get('JWT_SECRET', '');
        }
        if ($key === '') {
            throw new RuntimeException('Encryption key not configured. Set APP_KEY or JWT_SECRET in .env.');
        }
        return hash('sha256', $key, true);
    }
}
