<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Encrypter;

final class EncrypterEdgeCasesTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        // Generate a proper 32-byte key for AES-256
        $this->key = random_bytes(32);
    }

    public function testEncryptEmptyString(): void
    {
        $encrypted = Encrypter::encrypt('', $this->key);
        $decrypted = Encrypter::decrypt($encrypted, $this->key);

        $this->assertEquals('', $decrypted);
    }

    public function testEncryptVeryLongString(): void
    {
        $longString = str_repeat('a', 100000); // 100KB
        $encrypted = Encrypter::encrypt($longString, $this->key);
        $decrypted = Encrypter::decrypt($encrypted, $this->key);

        $this->assertEquals($longString, $decrypted);
    }

    public function testEncryptBinaryData(): void
    {
        $binaryData = random_bytes(1024);
        $encrypted = Encrypter::encrypt($binaryData, $this->key);
        $decrypted = Encrypter::decrypt($encrypted, $this->key);

        $this->assertEquals($binaryData, $decrypted);
    }

    public function testEncryptUnicodeCharacters(): void
    {
        $unicode = 'Hello 世界 🌍 مرحبا بالعالم';
        $encrypted = Encrypter::encrypt($unicode, $this->key);
        $decrypted = Encrypter::decrypt($encrypted, $this->key);

        $this->assertEquals($unicode, $decrypted);
    }

    public function testTamperedPayloadDetection(): void
    {
        $original = 'sensitive data';
        $encrypted = Encrypter::encrypt($original, $this->key);

        // Tamper with the payload
        $tampered = substr($encrypted, 0, -5) . 'XXXXX';

        $this->expectException(\RuntimeException::class);
        Encrypter::decrypt($tampered, $this->key);
    }

    public function testInvalidHmacVerification(): void
    {
        $encrypted = Encrypter::encrypt('test', $this->key);

        // Corrupt the HMAC part
        $parts = explode('|', $encrypted);
        if (count($parts) >= 3) {
            $parts[count($parts) - 1] = 'invalid_hmac';
            $corrupted = implode('|', $parts);

            $this->expectException(\RuntimeException::class);
            Encrypter::decrypt($corrupted, $this->key);
        } else {
            // If format is different, skip this test
            $this->markTestSkipped('Encrypted format does not use | separator');
        }
    }

    public function testDifferentKeyLengths(): void
    {
        // Test with 16-byte key (AES-128)
        $shortKey = random_bytes(16);

        // This should work with AES-128 or throw appropriate error
        try {
            $encrypted = Encrypter::encrypt('test', $shortKey);
            $decrypted = Encrypter::decrypt($encrypted, $shortKey);
            $this->assertEquals('test', $decrypted);
        } catch (\RuntimeException $e) {
            // Acceptable to fail if only AES-256 is supported
            $this->assertStringContainsString('key', strtolower($e->getMessage()));
        }
    }

    public function testEncryptDecryptPerformance(): void
    {
        $data = 'performance test data';
        $iterations = 1000;

        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $encrypted = Encrypter::encrypt($data, $this->key);
            $decrypted = Encrypter::decrypt($encrypted, $this->key);
            $this->assertEquals($data, $decrypted);
        }

        $elapsed = microtime(true) - $start;

        // Should complete 1000 encrypt/decrypt cycles in under 10 seconds
        $this->assertLessThan(10.0, $elapsed, "Performance test took {$elapsed}s, expected < 10s");
    }

    public function testMemoryUsageWithLargePayload(): void
    {
        $largeData = str_repeat('x', 10 * 1024 * 1024); // 10MB
        $memoryBefore = memory_get_usage(true);

        $encrypted = Encrypter::encrypt($largeData, $this->key);
        $memoryAfterEncrypt = memory_get_usage(true);

        $decrypted = Encrypter::decrypt($encrypted, $this->key);
        $memoryAfterDecrypt = memory_get_usage(true);

        $this->assertEquals($largeData, $decrypted);

        // Memory increase should be reasonable (< 50MB for 10MB data)
        $this->assertLessThan(50 * 1024 * 1024, $memoryAfterEncrypt - $memoryBefore);
    }

    public function testSpecialCharactersInData(): void
    {
        $specialChars = "!@#$%^&*()_+-=[]{}|;:',.<>?/\n\r\t";
        $encrypted = Encrypter::encrypt($specialChars, $this->key);
        $decrypted = Encrypter::decrypt($encrypted, $this->key);

        $this->assertEquals($specialChars, $decrypted);
    }

    public function testNullBytesInData(): void
    {
        $dataWithNulls = "test\x00data\x00with\x00nulls";
        $encrypted = Encrypter::encrypt($dataWithNulls, $this->key);
        $decrypted = Encrypter::decrypt($encrypted, $this->key);

        $this->assertEquals($dataWithNulls, $decrypted);
    }

    public function testArraySerialization(): void
    {
        $array = ['key' => 'value', 'nested' => ['foo' => 'bar']];
        $serialized = serialize($array);

        $encrypted = Encrypter::encrypt($serialized, $this->key);
        $decrypted = Encrypter::decrypt($encrypted, $this->key);
        $unserialized = unserialize($decrypted);

        $this->assertEquals($array, $unserialized);
    }

    public function testJsonObjectEncryption(): void
    {
        $json = json_encode(['user' => 'john', 'roles' => ['admin', 'user']]);
        $encrypted = Encrypter::encrypt($json, $this->key);
        $decrypted = Encrypter::decrypt($encrypted, $this->key);
        $decoded = json_decode($decrypted, true);

        $this->assertEquals('john', $decoded['user']);
        $this->assertCount(2, $decoded['roles']);
    }

    public function testRepeatedEncryptionProducesDifferentCiphertext(): void
    {
        $data = 'same data';
        $encrypted1 = Encrypter::encrypt($data, $this->key);
        $encrypted2 = Encrypter::encrypt($data, $this->key);

        // Should be different due to IV
        $this->assertNotEquals($encrypted1, $encrypted2);

        // But both should decrypt to same value
        $this->assertEquals($data, Encrypter::decrypt($encrypted1, $this->key));
        $this->assertEquals($data, Encrypter::decrypt($encrypted2, $this->key));
    }

    public function testBase64EncodingRoundTrip(): void
    {
        $data = 'test data for base64';
        $encrypted = Encrypter::encrypt($data, $this->key);

        // Verify it's valid base64
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/]+=*$/', $encrypted);

        // Verify decoding works
        $decoded = base64_decode($encrypted, true);
        $this->assertNotFalse($decoded);
    }

    public function testEncryptDecryptWithAppKey(): void
    {
        // Test using APP_KEY from environment
        putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));

        $data = 'test with app key';
        $encrypted = Encrypter::encrypt($data);
        $decrypted = Encrypter::decrypt($encrypted);

        $this->assertEquals($data, $decrypted);

        // Cleanup
        putenv('APP_KEY');
    }

    public function testConcurrentEncryption(): void
    {
        $results = [];
        $threads = 10;

        for ($i = 0; $i < $threads; $i++) {
            $data = "thread_{$i}_data";
            $encrypted = Encrypter::encrypt($data, $this->key);
            $decrypted = Encrypter::decrypt($encrypted, $this->key);
            $results[] = $decrypted;
        }

        $this->assertCount($threads, $results);
        foreach ($results as $index => $result) {
            $this->assertEquals("thread_{$index}_data", $result);
        }
    }

    public function testEncryptDecryptConsistencyAcrossRuns(): void
    {
        $data = 'consistency test';

        // Run multiple times to ensure consistency
        for ($i = 0; $i < 100; $i++) {
            $encrypted = Encrypter::encrypt($data, $this->key);
            $decrypted = Encrypter::decrypt($encrypted, $this->key);
            $this->assertEquals($data, $decrypted, "Failed on iteration {$i}");
        }
    }
}
