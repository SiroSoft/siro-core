<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\OAuth2\AuthorizationServer;
use Siro\Core\Auth\OAuth2\OAuthClient;
use Siro\Core\Database;

/**
 * Real-HTTP end-to-end tests for the G2 OAuth2 bridge.
 *
 * Boots the fixture app (tests/fixtures/oauth2-app) with `php -S` and drives
 * all three grants over real HTTP with curl (Http:: blocks SSRF to 127.0.0.1,
 * so raw curl is used — same lesson as the C3 webhook E2E).
 */
final class OAuth2E2eTest extends TestCase
{
    /** @var resource|null */
    private static $serverProc = null;

    private static string $tmpDir = '';
    private static int $port = 0;

    /** @var array{client_id: string, client_secret: string} */
    private static array $client = [];

    /** @var array{client_id: string, client_secret: string} */
    private static array $publicClient = [];

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_oauth2_e2e_' . uniqid('', true);
        $fixture = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'oauth2-app';
        self::copyDir($fixture, self::$tmpDir);
        mkdir(self::$tmpDir . '/storage/logs', 0777, true);
        mkdir(self::$tmpDir . '/storage/framework', 0777, true);

        // RSA key pair + defuse encryption key (generated, never hard-coded).
        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $priv = '';
        openssl_pkey_export($res, $priv);
        $details = openssl_pkey_get_details($res);
        $pub = is_array($details) ? (string) ($details['key'] ?? '') : '';
        file_put_contents(self::$tmpDir . '/storage/oauth-private.key', $priv);
        file_put_contents(self::$tmpDir . '/storage/oauth-public.key', $pub);
        $encryptionKey = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();

        $dbFile = self::$tmpDir . '/storage/oauth2.sqlite';
        $env = implode("\n", [
            'APP_ENV=testing',
            'APP_DEBUG=false',
            'OAUTH_PRIVATE_KEY_PATH=' . self::$tmpDir . '/storage/oauth-private.key',
            'OAUTH_PUBLIC_KEY_PATH=' . self::$tmpDir . '/storage/oauth-public.key',
            'OAUTH_ENCRYPTION_KEY=' . $encryptionKey,
            'OAUTH2_DB_PATH=' . $dbFile,
            'JWT_SECRET=' . bin2hex(random_bytes(16)),
            '',
        ]);
        file_put_contents(self::$tmpDir . '/.env', $env);

        // Free port.
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        self::$port = (int) $port;

        // Clients in the server's DB (before the server starts).
        Database::configure(['driver' => 'sqlite', 'database' => $dbFile]);
        AuthorizationServer::createTables();
        self::$client = OAuthClient::create([
            'name' => 'E2E Partner',
            'redirect_uris' => ['http://127.0.0.1:' . self::$port . '/callback'],
            'grants' => ['authorization_code', 'client_credentials', 'refresh_token'],
            'scopes' => ['read'],
        ]);
        self::$publicClient = OAuthClient::create([
            'name' => 'E2E Mobile',
            'redirect_uris' => ['myapp://callback'],
            'grants' => ['authorization_code', 'refresh_token'],
            'scopes' => ['read'],
            'is_confidential' => false,
        ]);
        Database::purgeAll();

        // Start the real HTTP server (array form: no sh -c, no orphan).
        $log = self::$tmpDir . '/server.log';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['file', $log, 'w'],
        ];
        $envVars = [
            'PATH' => (string) getenv('PATH'),
            'SIRO_VENDOR_AUTOLOAD' => dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', self::$tmpDir . '/public'],
            $descriptors,
            $pipes,
            self::$tmpDir,
            $envVars,
        );
        if (!is_resource($proc)) {
            self::fail('Failed to start php -S for OAuth2 E2E tests');
        }
        self::$serverProc = $proc;

        $deadline = time() + 15;
        $up = false;
        while (time() < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 1);
            if ($conn !== false) {
                fclose($conn);
                $up = true;
                break;
            }
            usleep(200000);
        }
        if (!$up) {
            $logContents = is_file($log) ? (string) file_get_contents($log) : '';
            self::fail('OAuth2 E2E server did not come up on port ' . self::$port . ': ' . $logContents);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
        Database::purgeAll();
        if (self::$tmpDir !== '' && is_dir(self::$tmpDir)) {
            self::deleteDir(self::$tmpDir);
        }
    }

    /** @return array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>|null} */
    private function http(string $method, string $path, array $data = [], array $headers = []): array
    {
        $url = 'http://127.0.0.1:' . self::$port . $path;
        $args = ['curl', '-s', '-i', '--max-time', '10', '-X', $method];
        foreach ($headers as $header) {
            $args[] = '-H';
            $args[] = $header;
        }
        if ($method === 'GET' && $data !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
        } elseif ($data !== []) {
            $args[] = '--data';
            $args[] = http_build_query($data);
        }
        $args[] = $url;
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $lines = [];
        exec($cmd, $lines, $code);
        $raw = implode("\n", $lines);
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $head = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $status = 0;
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/m', $head, $m) === 1) {
            $status = (int) $m[1];
        }
        $responseHeaders = [];
        foreach (explode("\n", $head) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }
        $json = json_decode($body, true);
        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $body,
            'json' => is_array($json) ? $json : null,
        ];
    }

    private static function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return [$verifier, $challenge];
    }

    private function issueClientCredentialsToken(string $scope = 'read'): string
    {
        $response = $this->http('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => self::$client['client_id'],
            'client_secret' => self::$client['client_secret'],
            'scope' => $scope,
        ]);
        $this->assertSame(200, $response['status'], $response['body']);
        return (string) ($response['json']['access_token'] ?? '');
    }

    /** @return array{0: string, 1: string} [access_token, refresh_token] */
    private function issueAuthCodeTokens(): array
    {
        [$verifier, $challenge] = self::pkcePair();
        $auth = $this->http('GET', '/oauth/authorize', [
            'response_type' => 'code',
            'client_id' => self::$publicClient['client_id'],
            'redirect_uri' => 'myapp://callback',
            'scope' => 'read',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'auto_approve' => '1',
        ]);
        $this->assertSame(302, $auth['status'], $auth['body']);
        $location = $auth['headers']['location'] ?? '';
        $this->assertStringStartsWith('myapp://callback', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state'] ?? null);
        $code = (string) ($query['code'] ?? '');
        $this->assertNotSame('', $code);

        $token = $this->http('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'myapp://callback',
            'client_id' => self::$publicClient['client_id'],
            'code_verifier' => $verifier,
        ]);
        $this->assertSame(200, $token['status'], $token['body']);
        return [
            (string) ($token['json']['access_token'] ?? ''),
            (string) ($token['json']['refresh_token'] ?? ''),
        ];
    }

    private static function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0777, true);
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $s = $src . DIRECTORY_SEPARATOR . $item;
            $d = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($s)) {
                self::copyDir($s, $d);
            } else {
                copy($s, $d);
            }
        }
    }

    private static function deleteDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // ---- tests ----

    public function testClientCredentialsFlowEndToEnd(): void
    {
        $token = $this->http('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => self::$client['client_id'],
            'client_secret' => self::$client['client_secret'],
            'scope' => 'read',
        ]);
        $this->assertSame(200, $token['status'], $token['body']);
        $this->assertSame('Bearer', $token['json']['token_type'] ?? null);
        $this->assertSame(3600, $token['json']['expires_in'] ?? null);
        $this->assertArrayNotHasKey('refresh_token', $token['json'] ?? []);
        $accessToken = (string) ($token['json']['access_token'] ?? '');
        $this->assertNotSame('', $accessToken);

        $data = $this->http('GET', '/api/partner/data', [], ['Authorization: Bearer ' . $accessToken]);
        $this->assertSame(200, $data['status'], $data['body']);
        $this->assertSame('partner-data', $data['json']['data'] ?? null);
    }

    public function testScopeEnforcementEndToEnd(): void
    {
        $token = $this->issueClientCredentialsToken('read');
        $write = $this->http('POST', '/api/partner/write', [], ['Authorization: Bearer ' . $token]);
        // The middleware returns 403 (verified in-process by AuthOAuth2Test).
        // Under `php -S`, PHP's cli-server SAPI forces the status to 401
        // whenever a WWW-Authenticate header is present — verified with a
        // hardcoded 403 + WWW-Authenticate debug route. Production SAPIs
        // (FPM/RoadRunner) preserve 403. Assert the contract (body + header)
        // and accept either status here.
        $this->assertContains($write['status'], [401, 403], $write['body']);
        $this->assertSame('insufficient_scope', $write['json']['meta']['error_code'] ?? null);
        $this->assertStringContainsString(
            'insufficient_scope',
            $write['headers']['www-authenticate'] ?? '',
        );
    }

    public function testAuthorizationCodeFlowEndToEnd(): void
    {
        [$accessToken, $refreshToken] = $this->issueAuthCodeTokens();
        $this->assertNotSame('', $accessToken);
        $this->assertNotSame('', $refreshToken);

        $data = $this->http('GET', '/api/partner/data', [], ['Authorization: Bearer ' . $accessToken]);
        $this->assertSame(200, $data['status'], $data['body']);
        $this->assertSame('partner-data', $data['json']['data'] ?? null);
    }

    public function testAuthorizationCodeFlowWithRealCallback(): void
    {
        [$verifier, $challenge] = self::pkcePair();
        $callback = 'http://127.0.0.1:' . self::$port . '/callback';
        $auth = $this->http('GET', '/oauth/authorize', [
            'response_type' => 'code',
            'client_id' => self::$client['client_id'],
            'redirect_uri' => $callback,
            'scope' => 'read',
            'state' => 'abc',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'auto_approve' => '1',
        ]);
        $this->assertSame(302, $auth['status'], $auth['body']);
        $location = $auth['headers']['location'] ?? '';
        $this->assertStringStartsWith($callback, $location);

        // Follow the redirect for real: the fixture /callback echoes the code.
        $cb = $this->http('GET', (string) parse_url($location, PHP_URL_PATH) . '?' . (string) parse_url($location, PHP_URL_QUERY));
        $this->assertSame(200, $cb['status'], $cb['body']);
        $this->assertSame('abc', $cb['json']['state'] ?? null);
        $code = (string) ($cb['json']['code'] ?? '');
        $this->assertNotSame('', $code);

        $token = $this->http('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $callback,
            'client_id' => self::$client['client_id'],
            'client_secret' => self::$client['client_secret'],
            'code_verifier' => $verifier,
        ]);
        $this->assertSame(200, $token['status'], $token['body']);
        $this->assertNotSame('', (string) ($token['json']['access_token'] ?? ''));
    }

    public function testRefreshTokenFlowEndToEnd(): void
    {
        [, $refreshToken] = $this->issueAuthCodeTokens();

        $refreshed = $this->http('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => self::$publicClient['client_id'],
        ]);
        $this->assertSame(200, $refreshed['status'], $refreshed['body']);
        $newAccess = (string) ($refreshed['json']['access_token'] ?? '');
        $this->assertNotSame('', $newAccess);

        $data = $this->http('GET', '/api/partner/data', [], ['Authorization: Bearer ' . $newAccess]);
        $this->assertSame(200, $data['status'], $data['body']);

        // Rotation: the old refresh token is dead.
        $reuse = $this->http('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => self::$publicClient['client_id'],
        ]);
        $this->assertSame(400, $reuse['status'], $reuse['body']);
        $this->assertSame('invalid_grant', $reuse['json']['error'] ?? null);
    }

    public function testRevokeFlowEndToEnd(): void
    {
        $token = $this->issueClientCredentialsToken('read');
        $before = $this->http('GET', '/api/partner/data', [], ['Authorization: Bearer ' . $token]);
        $this->assertSame(200, $before['status'], $before['body']);

        $revoke = $this->http('POST', '/oauth/revoke', [
            'token' => $token,
            'token_type_hint' => 'access_token',
            'client_id' => self::$client['client_id'],
            'client_secret' => self::$client['client_secret'],
        ]);
        $this->assertSame(200, $revoke['status'], $revoke['body']);

        $after = $this->http('GET', '/api/partner/data', [], ['Authorization: Bearer ' . $token]);
        $this->assertSame(401, $after['status'], $after['body']);
    }

    public function testMissingTokenEndToEnd(): void
    {
        $response = $this->http('GET', '/api/partner/data');
        $this->assertSame(401, $response['status'], $response['body']);
    }

    public function testWrongSecretEndToEnd(): void
    {
        $response = $this->http('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => self::$client['client_id'],
            'client_secret' => 'wrong',
            'scope' => 'read',
        ]);
        $this->assertSame(401, $response['status'], $response['body']);
        $this->assertSame('invalid_client', $response['json']['error'] ?? null);
    }

    public function testPublicClientCannotUseClientCredentials(): void
    {
        $response = $this->http('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => self::$publicClient['client_id'],
            'scope' => 'read',
        ]);
        $this->assertSame(401, $response['status'], $response['body']);
        $this->assertSame('invalid_client', $response['json']['error'] ?? null);
    }
}
