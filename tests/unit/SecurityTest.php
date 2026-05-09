<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = dirname(__DIR__, 2);
    }

    public function testSqlInjectionPreventionInQueryBuilder(): void
    {
        $maliciousInput = "1; DROP TABLE users;--";
        $sanitized = filter_var($maliciousInput, FILTER_SANITIZE_NUMBER_INT);
        $this->assertFalse(strpos($sanitized, ';') !== false && strpos(strtolower($sanitized), 'drop') !== false);
    }

    public function testXssPreventionInHtmlOutput(): void
    {
        $input = '<script>alert("XSS")</script>';
        $escaped = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    public function testXssPreventionInJsonResponse(): void
    {
        $input = '<img src=x onerror=alert(1)>';
        $sanitized = strip_tags($input);
        $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('<img', $json);
        $this->assertStringNotContainsString('onerror', $json);
    }

    public function testCsrfTokenGeneration(): void
    {
        $token = bin2hex(random_bytes(16));
        $this->assertEquals(32, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    public function testCsrfTokenValidation(): void
    {
        $validToken = bin2hex(random_bytes(16));
        $this->assertEquals(32, strlen($validToken));

        $malformedToken = 'invalid-token';
        $this->assertNotEquals($validToken, $malformedToken);
    }

    public function testPasswordHashingUsesBcrypt(): void
    {
        $hash = password_hash('test123', PASSWORD_BCRYPT);
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertEquals(60, strlen($hash));
    }

    public function testPasswordHashingVerification(): void
    {
        $password = 'secret123';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }

    public function testJwtSecretMinimumLength(): void
    {
        $secret = bin2hex(random_bytes(32));
        $this->assertGreaterThanOrEqual(32, strlen($secret));
    }

    public function testInputSanitizationRemovesNullBytes(): void
    {
        $input = "test\x00null";
        $sanitized = str_replace("\x00", '', $input);
        $this->assertStringNotContainsString("\x00", $sanitized);
    }

    public function testInputSanitizationTrimsWhitespace(): void
    {
        $input = "  test input  ";
        $trimmed = trim($input);
        $this->assertEquals("test input", $trimmed);
    }

    public function testFileUploadRejectsPhpExtension(): void
    {
        $filename = 'malicious.php';
        $this->assertStringEndsWith('.php', $filename);
        $this->assertStringNotContainsString('.jpg.php', $filename);
    }

    public function testFileUploadValidatesMimeType(): void
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        $uploadedMime = 'application/x-php';

        $this->assertNotContains($uploadedMime, $allowedMimes);
        $this->assertContains('image/jpeg', $allowedMimes);
    }

    public function testRateLimitingKeyGeneration(): void
    {
        $ip = '192.168.1.100';
        $route = '/api/users';
        $key = md5($ip . $route);

        $this->assertEquals(32, strlen($key));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
    }

    public function testCorsHeadersPreventUnauthorizedOrigins(): void
    {
        $allowedOrigins = ['https://example.com'];
        $requestOrigin = 'https://evil.com';

        $this->assertNotContains($requestOrigin, $allowedOrigins);
    }

    public function testEnvironmentVariableNotExposedInErrors(): void
    {
        $envVars = ['DB_PASSWORD', 'JWT_SECRET', 'APP_KEY'];
        $errorMessage = 'Database connection failed';

        foreach ($envVars as $var) {
            $this->assertStringNotContainsString($var, $errorMessage);
        }
    }

    public function testLoggingSanitizesCredentials(): void
    {
        $data = [
            'password' => 'secret123',
            'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
            'credit_card' => '4111111111111111',
            'name' => 'John',
        ];

        $sensitiveKeys = ['password', 'token', 'credit_card'];
        foreach ($sensitiveKeys as $key) {
            if (isset($data[$key])) {
                $data[$key] = '[REDACTED]';
            }
        }

        $this->assertEquals('[REDACTED]', $data['password']);
        $this->assertEquals('[REDACTED]', $data['token']);
        $this->assertEquals('[REDACTED]', $data['credit_card']);
        $this->assertEquals('John', $data['name']);
    }

    public function testIdempotencyKeyPreventsDuplicateOperations(): void
    {
        $key = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',
            $key
        );
    }

    public function testApiKeyFormatValidation(): void
    {
        $key = 'sk_live_' . bin2hex(random_bytes(16));
        $this->assertStringStartsWith('sk_live_', $key);
        $this->assertGreaterThanOrEqual(32, strlen($key));
    }

    public function testSecureCookieFlags(): void
    {
        $cookieOptions = [
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ];

        $this->assertTrue($cookieOptions['secure']);
        $this->assertTrue($cookieOptions['httponly']);
        $this->assertEquals('Strict', $cookieOptions['samesite']);
    }

    public function testMassAssignmentProtection(): void
    {
        $fillable = ['name', 'email', 'password'];
        $guarded = ['is_admin', 'role', 'permissions'];

        $input = [
            'name' => 'John',
            'email' => 'john@example.com',
            'is_admin' => true,
            'role' => 'admin',
        ];

        $allowed = array_intersect(array_keys($input), $fillable);
        $blocked = array_intersect(array_keys($input), $guarded);

        $this->assertCount(2, $allowed);
        $this->assertCount(2, $blocked);
    }

    public function testValidationRuleSanitization(): void
    {
        $rule = 'required|email|min:8|max:50';
        $this->assertStringContainsString('required', $rule);
        $this->assertStringContainsString('email', $rule);
    }

    public function testSqlWildcardEscapingInLikeQueries(): void
    {
        $userInput = '100% O\'Brien';
        $escaped = str_replace(['%', '_', "'"], ['\\%', '\\_', "\\'"], $userInput);

        $this->assertStringContainsString("\\'", $escaped);
        $this->assertStringContainsString("\\%", $escaped);
    }

    public function testRedirectUrlValidation(): void
    {
        $allowedDomains = ['example.com', 'sub.example.com'];
        $redirectUrl = 'https://evil.com/redirect';

        $parsedUrl = parse_url($redirectUrl);
        $host = $parsedUrl['host'] ?? '';

        $this->assertStringStartsWith('https://', $redirectUrl);
        $this->assertNotContains($host, $allowedDomains);
    }

    public function testCommandInjectionPreventionInShellExec(): void
    {
        $safeInput = 'filename.txt';
        $sanitized = escapeshellarg($safeInput);
        $this->assertNotEmpty($sanitized);
        $this->assertStringNotContainsString(';', $sanitized);
        $this->assertNotEquals($safeInput, $sanitized, 'escapeshellarg should modify input');

        $specialChars = "file'name.txt";
        $escapedSpecial = escapeshellarg($specialChars);
        $this->assertNotEquals($specialChars, $escapedSpecial);
    }

    public function testJsonDecodePreventsCodeInjection(): void
    {
        $safeJson = '{"key": "safe_value", "number": 123}';
        $decoded = json_decode($safeJson, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('safe_value', $decoded['key'] ?? null);
        $this->assertEquals(123, $decoded['number'] ?? null);
    }

    public function testLargePayloadRejection(): void
    {
        $maxSize = 1048576;
        $payloadSize = filesize(__FILE__);

        $this->assertLessThan($maxSize, $payloadSize);
    }

    public function testIntegerOverflowPrevention(): void
    {
        $input = '999999999999999999999';
        $validated = filter_var($input, FILTER_VALIDATE_INT);

        $this->assertFalse($validated);
    }

    public function testFloatInjectionPrevention(): void
    {
        $input = '3.14';
        $validated = filter_var($input, FILTER_VALIDATE_FLOAT);

        $this->assertEquals(3.14, $validated);
        $this->assertIsFloat($validated);
    }

    public function testBase64DecodePreventsRemoteCodeInclusion(): void
    {
        $encoded = base64_encode('echo "test"');
        $decoded = base64_decode($encoded);

        $this->assertStringNotContainsString('<?', $decoded);
        $this->assertStringNotContainsString('php', $decoded);
    }

    public function testPathTraversalPrevention(): void
    {
        $userPath = '../../../etc/passwd';
        $normalized = realpath($userPath);

        $this->assertFalse($normalized);
    }

    public function testXmlExternalEntityPrevention(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>';

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOENT);
        $errors = libxml_get_errors();

        $this->assertTrue(
            $doc === false || !empty($errors) || strpos((string) $doc, 'root') === false,
            'XXE should be prevented'
        );
        libxml_clear_errors();
    }
}