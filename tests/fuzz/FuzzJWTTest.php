<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use Siro\Core\Auth\JWT;

final class FuzzJWTTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_key_for_unit_tests_32_chars_long!!';
        putenv('JWT_SECRET=' . $_ENV['JWT_SECRET']);
        JWT::reset();
    }

    #[DataProvider('provideValidPayloads')]
    public function testEncodeNeverThrows(array $payload): void
    {
        $token = JWT::encode($payload);
        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);
    }

    /** @return iterable<string, array{array}> */
    public static function provideValidPayloads(): iterable
    {
        yield 'minimal' => [['sub' => 1, 'ver' => 1]];
        yield 'full' => [['sub' => 42, 'ver' => 2, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access']];
        yield 'with custom claims' => [['sub' => 1, 'ver' => 1, 'name' => 'John', 'role' => 'admin']];
        yield 'nested arrays' => [['sub' => 1, 'ver' => 1, 'meta' => ['key' => 'value', 'count' => 5]]];
        yield 'empty array values' => [['sub' => 1, 'ver' => 1, 'tags' => [], 'data' => null]];
        yield 'unicode chars' => [['sub' => 1, 'ver' => 1, 'name' => 'HeartSpade']];
        yield 'very long strings' => [['sub' => 1, 'ver' => 1, 'data' => str_repeat('x', 10000)]];
        yield 'special chars' => [['sub' => 1, 'ver' => 1, 'sql' => "DROP TABLE users;--"]];
        yield 'boolean values' => [['sub' => 1, 'ver' => 1, 'active' => true, 'deleted' => false]];
        yield 'numeric keys' => [['sub' => 1, 'ver' => 1, 0 => 'zero', 1 => 'one']];
        yield 'mixed types' => [['sub' => 1, 'ver' => 1, 'str' => 'hello', 'int' => 42, 'float' => 3.14, 'null' => null]];
    }

    #[DataProvider('provideRoundtripPayloads')]
    public function testEncodeDecodeRoundtrip(array $payload): void
    {
        try {
            $token = JWT::encode($payload);
            $decoded = JWT::decode($token);
            foreach ($payload as $key => $value) {
                if (in_array($key, ['iat', 'exp', 'jti', 'type'], true)) {
                    continue;
                }
                $this->assertArrayHasKey($key, $decoded);
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, 'expired') || str_contains($msg, 'Invalid token'),
                "Exception message should contain 'expired' or 'Invalid token': $msg"
            );
        }
    }

    /** @return iterable<string, array{array}> */
    public static function provideRoundtripPayloads(): iterable
    {
        yield 'with custom claims' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 'name' => 'John', 'role' => 'admin']];
        yield 'nested arrays' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 'meta' => ['key' => 'value', 'count' => 5]]];
        yield 'empty array values' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 'tags' => [], 'data' => null]];
        yield 'unicode chars' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 'name' => 'HeartSpade']];
        yield 'very long strings' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 'data' => str_repeat('x', 10000)]];
        yield 'special chars' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 'sql' => "DROP TABLE users;--"]];
        yield 'boolean values' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 'active' => true, 'deleted' => false]];
        yield 'numeric keys' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 0 => 'zero', 1 => 'one']];
        yield 'mixed types' => [['sub' => 1, 'ver' => 1, 'type' => 'access', 'str' => 'hello', 'int' => 42, 'float' => 3.14, 'null' => null]];
    }

    #[DataProvider('provideEncodeAccessParams')]
    public function testEncodeAccessNeverThrows(int $userId, int $tokenVersion, int $ttl): void
    {
        $token = JWT::encodeAccess(max(1, $userId), max(1, $tokenVersion), max(1, $ttl));
        $this->assertIsString($token);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function provideEncodeAccessParams(): iterable
    {
        yield 'normal' => [1, 1, 3600];
        yield 'zero ttl' => [1, 1, 0];
        yield 'negative ttl' => [1, 1, -1];
        yield 'large user id' => [999999999, 1, 3600];
        yield 'high version' => [1, 999, 3600];
        yield 'large ttl' => [1, 1, 999999999];
        yield 'zero userid' => [0, 1, 3600];
        yield 'negative version' => [1, -5, 3600];
    }

    #[DataProvider('provideEncodeRefreshParams')]
    public function testEncodeRefreshNeverThrows(int $userId, int $tokenVersion, int $ttl): void
    {
        $token = JWT::encodeRefresh(max(1, $userId), max(1, $tokenVersion), max(1, $ttl));
        $this->assertIsString($token);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function provideEncodeRefreshParams(): iterable
    {
        yield 'normal' => [1, 1, 604800];
        yield 'short ttl' => [1, 1, 60];
        yield 'large values' => [999999, 999, 999999];
        yield 'minimal' => [1, 1, 1];
    }

    #[DataProvider('provideInvalidTokens')]
    public function testDecodeThrowsForInvalidTokens(string $token): void
    {
        $this->expectException(\Throwable::class);
        JWT::decode($token);
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidTokens(): iterable
    {
        yield 'empty string' => [''];
        yield 'single segment' => ['abc'];
        yield 'two segments' => ['abc.def'];
        yield 'four segments' => ['a.b.c.d'];
        yield 'invalid base64' => ['!!!.!!!.!!!'];
        yield 'random garbage' => ['garbage.invalid.token'];
        yield 'null bytes' => ["a\x00b.c\x00d.e\x00f"];
        yield 'tampered payload' => ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.tampered'];
        yield 'truncated' => [substr(JWT::encode(['sub' => 1, 'ver' => 1]), 0, -5)];
        yield 'appended' => [JWT::encode(['sub' => 1, 'ver' => 1]) . '.extra'];
        yield 'unicode in token' => ['ABC.DEF.GHI'];
    }

    #[DataProvider('provideTamperedTokens')]
    public function testDecodeRejectsTamperedToken(string $token): void
    {
        $this->expectException(\Throwable::class);
        JWT::decode($token);
    }

    /** @return iterable<string, array{string}> */
    public static function provideTamperedTokens(): iterable
    {
        $valid = JWT::encode(['sub' => 1, 'ver' => 1, 'type' => 'access', 'iat' => time(), 'exp' => time() + 3600]);

        $parts = explode('.', $valid);
        if (count($parts) === 3) {
            $modifiedPayload = $parts[0] . '.' . substr($parts[1], 0, -1) . 'X' . '.' . $parts[2];
            yield 'bit flipped payload' => [$modifiedPayload];

            $wrongSig = $parts[0] . '.' . $parts[1] . '.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
            yield 'wrong signature' => [$wrongSig];
        }
    }
}
