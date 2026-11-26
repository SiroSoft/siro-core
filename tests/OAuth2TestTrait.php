<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use Siro\Core\Auth\JWT;
use Siro\Core\Auth\OAuth2\AuthorizationServer;
use Siro\Core\Auth\OAuth2\OAuthClient;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Shared OAuth2 test setup: temp sqlite DB, fresh RSA keys, two clients.
 *
 * Keys are generated once per test class (RSA 2048 gen is slow); the DB and
 * clients are rebuilt for every test so tests never see each other's rows.
 */
trait OAuth2TestTrait
{
    private static string $oauthTmpDir = '';
    private static string $oauthPrivateKey = '';
    private static string $oauthPublicKey = '';
    private static string $oauthEncryptionKey = '';
    private static string $oauthDbFile = '';

    /** @var array{client_id: string, client_secret: string, name: string, redirect_uris: array<int, string>, grants: array<int, string>, scopes: string, is_confidential: bool} */
    private array $oauthClient = [];

    /** @var array{client_id: string, client_secret: string, name: string, redirect_uris: array<int, string>, grants: array<int, string>, scopes: string, is_confidential: bool} */
    private array $oauthPublicClient = [];

    /** @var array{client_id: string, client_secret: string, name: string, redirect_uris: array<int, string>, grants: array<int, string>, scopes: string, is_confidential: bool} */
    private static array $oauthClientStatic = [];

    /** @var array{client_id: string, client_secret: string, name: string, redirect_uris: array<int, string>, grants: array<int, string>, scopes: string, is_confidential: bool} */
    private static array $oauthPublicClientStatic = [];

    private static function setUpOAuth2Class(): void
    {
        self::$oauthTmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_oauth2_' . uniqid('', true);
        mkdir(self::$oauthTmpDir, 0777, true);

        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($res === false) {
            self::fail('Could not generate RSA key pair for OAuth2 tests.');
        }
        $priv = '';
        openssl_pkey_export($res, $priv);
        $details = openssl_pkey_get_details($res);
        self::$oauthPrivateKey = $priv;
        self::$oauthPublicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
        self::$oauthEncryptionKey = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
        self::$oauthDbFile = self::$oauthTmpDir . DIRECTORY_SEPARATOR . 'oauth2.db';

        self::applyOAuth2Env();
        Database::configure(['driver' => 'sqlite', 'database' => self::$oauthDbFile]);
        AuthorizationServer::createTables();

        // Clients are created once per class: bcrypt cost 12 makes per-test
        // creation slow. Per-test isolation only wipes the token/code tables.
        self::$oauthClientStatic = OAuthClient::create([
            'name' => 'Test Partner',
            'redirect_uris' => ['http://127.0.0.1:18999/callback'],
            'grants' => ['authorization_code', 'client_credentials', 'refresh_token'],
            'scopes' => ['read'],
        ]);
        self::$oauthPublicClientStatic = OAuthClient::create([
            'name' => 'Test Mobile',
            'redirect_uris' => ['myapp://callback'],
            'grants' => ['authorization_code', 'refresh_token'],
            'scopes' => ['read'],
            'is_confidential' => false,
        ]);
    }

    private static function applyOAuth2Env(): void
    {
        $privFile = self::$oauthTmpDir . DIRECTORY_SEPARATOR . 'oauth-private.key';
        $pubFile = self::$oauthTmpDir . DIRECTORY_SEPARATOR . 'oauth-public.key';
        file_put_contents($privFile, self::$oauthPrivateKey);
        file_put_contents($pubFile, self::$oauthPublicKey);

        putenv('OAUTH_PRIVATE_KEY_PATH=' . $privFile);
        putenv('OAUTH_PUBLIC_KEY_PATH=' . $pubFile);
        putenv('OAUTH_ENCRYPTION_KEY=' . self::$oauthEncryptionKey);
        $_ENV['OAUTH_PRIVATE_KEY_PATH'] = $privFile;
        $_ENV['OAUTH_PUBLIC_KEY_PATH'] = $pubFile;
        $_ENV['OAUTH_ENCRYPTION_KEY'] = self::$oauthEncryptionKey;
        $_ENV['JWT_SECRET'] = bin2hex(random_bytes(16));
        Env::reset();
        AuthorizationServer::resetConfigCache();
        JWT::reset();
    }

    private function setUpOAuth2(): void
    {
        self::applyOAuth2Env();
        Database::configure(['driver' => 'sqlite', 'database' => self::$oauthDbFile]);
        foreach (['oauth_auth_codes', 'oauth_refresh_tokens', 'oauth_access_tokens'] as $table) {
            Database::execute('DELETE FROM ' . $table);
        }
        $this->oauthClient = self::$oauthClientStatic;
        $this->oauthPublicClient = self::$oauthPublicClientStatic;
    }

    private static function tearDownOAuth2Class(): void
    {
        Database::purgeAll();
        AuthorizationServer::resetConfigCache();
        JWT::reset();
        Env::reset();
        // Unset the OAuth2 env vars: without this the bridge still reports
        // isEnabled() === true for later test classes (leaked env), even
        // though the temp key files are already deleted.
        // NOTE: JWT_SECRET is deliberately NOT unset here — tests/bootstrap.php
        // sets it as ambient suite state, and CodeQualityFixesTest relies on
        // it being present. This trait overwrites it per test with a random
        // value; leaving the last random value satisfies the ambient contract
        // (any 32+ char test secret works).
        foreach (['OAUTH_PRIVATE_KEY_PATH', 'OAUTH_PUBLIC_KEY_PATH', 'OAUTH_ENCRYPTION_KEY', 'OAUTH_PRIVATE_KEY', 'OAUTH_PUBLIC_KEY'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        if (self::$oauthTmpDir !== '' && is_dir(self::$oauthTmpDir)) {
            foreach (glob(self::$oauthTmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(self::$oauthTmpDir);
        }
    }

    /** @param array<string, string> $body @param array<string, string> $headers */
    private function oauthTokenRequest(array $body, array $headers = []): Response
    {
        $request = new Request(
            'POST',
            '/oauth/token',
            [],
            array_merge(['Content-Type' => 'application/x-www-form-urlencoded'], $headers),
            $body,
        );
        return AuthorizationServer::handleTokenRequest($request);
    }

    /** @return array<string, mixed> */
    private function oauthTokenPayload(Response $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $response->payload();
        return $payload;
    }

    /** @return array<string, mixed> */
    private static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (!is_string($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return [$verifier, $challenge];
    }
}
