<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Defuse\Crypto\Key;
use RuntimeException;
use Throwable;

/**
 * Generate OAuth2 keys (RSA key pair + encryption key).
 *
 * Usage:
 *   php siro make:oauth-keys [--force]
 *
 * Writes:
 *   storage/oauth-private.key / storage/oauth-public.key (RSA 2048)
 *   .env: OAUTH_PRIVATE_KEY_PATH, OAUTH_PUBLIC_KEY_PATH, OAUTH_ENCRYPTION_KEY
 *
 * Refuses to overwrite existing keys without --force: rotating keys
 * invalidates every outstanding access/refresh token.
 */
final class MakeOAuthKeysCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        if (!class_exists(Key::class)) {
            $this->error('OAuth2 is not installed. Run: composer require league/oauth2-server laminas/laminas-diactoros');
            return 1;
        }

        $force = in_array('--force', $args, true);

        $storageDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        $privatePath = $storageDir . DIRECTORY_SEPARATOR . 'oauth-private.key';
        $publicPath = $storageDir . DIRECTORY_SEPARATOR . 'oauth-public.key';

        if ((is_file($privatePath) || is_file($publicPath)) && !$force) {
            $this->write('  OAuth keys already exist. Rotating them invalidates all outstanding tokens.');
            $this->write('  Run with --force to overwrite anyway: php siro make:oauth-keys --force');
            return 1;
        }

        try {
            if (!is_dir($storageDir) && !mkdir($storageDir, 0777, true) && !is_dir($storageDir)) {
                throw new RuntimeException('Cannot create storage directory: ' . $storageDir);
            }

            $keyConfig = [
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => 2048,
            ];
            $resource = openssl_pkey_new($keyConfig);
            if ($resource === false) {
                throw new RuntimeException('Failed to generate RSA key pair.');
            }
            $privateKey = '';
            if (!openssl_pkey_export($resource, $privateKey)) {
                throw new RuntimeException('Failed to export private key.');
            }
            $details = openssl_pkey_get_details($resource);
            $keyValue = is_array($details) ? ($details['key'] ?? '') : '';
            $publicKey = is_string($keyValue) ? $keyValue : '';
            if ($publicKey === '') {
                throw new RuntimeException('Failed to derive public key.');
            }

            $wrotePrivate = file_put_contents($privatePath, $privateKey, LOCK_EX) !== false;
            $wrotePublic = file_put_contents($publicPath, $publicKey, LOCK_EX) !== false;
            if (!$wrotePrivate || !$wrotePublic) {
                throw new RuntimeException('Failed to write key files.');
            }
            @chmod($privatePath, 0600);

            $encryptionKey = Key::createNewRandomKey()->saveToAsciiSafeString();

            $this->writeEnv('OAUTH_PRIVATE_KEY_PATH', 'storage/oauth-private.key');
            $this->writeEnv('OAUTH_PUBLIC_KEY_PATH', 'storage/oauth-public.key');
            $this->writeEnv('OAUTH_ENCRYPTION_KEY', $encryptionKey);

            $this->success('OAuth2 keys generated.');
            $this->write('  Private key: ' . $privatePath);
            $this->write('  Public key:  ' . $publicPath);
            $this->write('  .env updated with OAUTH_PRIVATE_KEY_PATH, OAUTH_PUBLIC_KEY_PATH, OAUTH_ENCRYPTION_KEY.');
            return 0;
        } catch (Throwable $e) {
            $this->error('Failed to generate OAuth2 keys: ' . $e->getMessage());
            return 1;
        }
    }

    private function writeEnv(string $key, string $value): void
    {
        $envPath = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) {
            $examplePath = $this->basePath . DIRECTORY_SEPARATOR . '.env.example';
            if (is_file($examplePath)) {
                copy($examplePath, $envPath);
            } else {
                file_put_contents($envPath, '');
            }
        }
        $content = (string) file_get_contents($envPath);
        if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $content) === 1) {
            $content = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $key . '=' . $value, $content);
        } else {
            $content = rtrim($content) . PHP_EOL . $key . '=' . $value . PHP_EOL;
        }
        file_put_contents($envPath, $content, LOCK_EX);
    }

    public function help(): void
    {
        echo "\nUsage: php siro make:oauth-keys [--force]\n\n";
        echo "Generates the RSA key pair + encryption key for OAuth2 (league/oauth2-server bridge).\n\n";
    }
}
