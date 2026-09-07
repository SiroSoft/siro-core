<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Encrypter;

final class EncrypterTest extends TestCase
{
    private string $originalKey;
    private string $originalEnvKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = getenv('APP_KEY') ?: '';
        $this->originalEnvKey = (string) ($_ENV['APP_KEY'] ?? '');
        $_ENV['APP_KEY'] = 'test_encryption_key_32chars!!';
        putenv('APP_KEY=test_encryption_key_32chars!!');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->originalEnvKey === '') {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $this->originalEnvKey;
        }
        putenv('APP_KEY=' . $this->originalKey);
    }

    public function testEncryptDecrypt(): void
    {
        $original = 'Hello World';
        $encrypted = Encrypter::encrypt($original);
        $this->assertIsString($encrypted);
        $this->assertNotSame($original, $encrypted);

        $decrypted = Encrypter::decrypt($encrypted);
        $this->assertSame($original, $decrypted);
    }

    public function testEncryptDecryptWithCustomKey(): void
    {
        $original = 'Sensitive Data 123!';
        $key = 'my_custom_key_16_chars!!';

        $encrypted = Encrypter::encrypt($original, $key);
        $decrypted = Encrypter::decrypt($encrypted, $key);
        $this->assertSame($original, $decrypted);
    }

    public function testDifferentKeysProduceDifferentCiphertext(): void
    {
        $data = 'test_data';
        $e1 = Encrypter::encrypt($data, 'key_one_12345678!!');
        $e2 = Encrypter::encrypt($data, 'key_two_12345678!!');
        $this->assertNotSame($e1, $e2);
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $encrypted = Encrypter::encrypt('secret', 'correct_key');
        Encrypter::decrypt($encrypted, 'wrong_key');
    }

    public function testDecryptInvalidPayloadFails(): void
    {
        $this->expectException(\RuntimeException::class);
        Encrypter::decrypt('invalid_payload');
    }

    public function testDecryptTamperedDataFails(): void
    {
        $encrypted = Encrypter::encrypt('important');

        // Flip a bit inside the ciphertext region (after the 32-byte HMAC
        // and 16-byte IV). Replacing a base64 character is a no-op ~1.5% of
        // the time (when the original character already equals the
        // replacement), which made this test flaky on CI.
        $raw = base64_decode($encrypted, true);
        $this->assertIsString($raw);
        $raw[63] = $raw[63] ^ "\x01";
        $tampered = base64_encode($raw);
        $this->assertNotSame($encrypted, $tampered);

        $this->expectException(\RuntimeException::class);
        Encrypter::decrypt($tampered);
    }

    public function testEncryptDecryptUnicode(): void
    {
        $original = 'Xin chào thế giới! テスト';
        $encrypted = Encrypter::encrypt($original);
        $decrypted = Encrypter::decrypt($encrypted);
        $this->assertSame($original, $decrypted);
    }

    public function testEncryptDecryptEmptyString(): void
    {
        $original = '';
        $encrypted = Encrypter::encrypt($original);
        $decrypted = Encrypter::decrypt($encrypted);
        $this->assertSame($original, $decrypted);
    }
}
