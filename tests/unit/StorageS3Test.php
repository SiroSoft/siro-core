<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;
use Siro\Core\Storage;

/**
 * Storage S3 driver + signing internals. S3 ops hit a fake bucket ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ return
 * false/null, covering the code paths without a real S3.
 */
final class StorageS3Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('STORAGE_DRIVER=s3');
        putenv('STORAGE_S3_KEY=AKIDEXAMPLE');
        putenv('STORAGE_S3_SECRET=wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY');
        putenv('STORAGE_S3_REGION=us-east-1');
        putenv('STORAGE_S3_BUCKET=test-bucket');
        putenv('STORAGE_S3_ENDPOINT=http://127.0.0.1:9');
        Env::reset();
        Storage::reset();
        Storage::boot();
    }

    protected function tearDown(): void
    {
        Env::reset();
        putenv('STORAGE_DRIVER');
        putenv('STORAGE_S3_KEY');
        putenv('STORAGE_S3_SECRET');
        putenv('STORAGE_S3_REGION');
        putenv('STORAGE_S3_BUCKET');
        putenv('STORAGE_S3_ENDPOINT');
        putenv('STORAGE_PATH');
        Storage::reset();
        Storage::boot(); // restore local driver
        parent::tearDown();
    }

    public function testS3PutFails(): void
    {
        set_error_handler(static function (): bool { return true; });
        ob_start();
        $result = Storage::put('test.txt', 'content');
        ob_end_clean();
        $this->assertFalse($result);
        restore_error_handler();
    }

    public function testS3GetFails(): void
    {
        set_error_handler(static function (): bool { return true; });
        ob_start();
        $result = Storage::get('test.txt');
        ob_end_clean();
        $this->assertNull($result);
        restore_error_handler();
    }

    public function testS3ExistsFails(): void
    {
        set_error_handler(static function (): bool { return true; });
        ob_start();
        $result = Storage::exists('test.txt');
        ob_end_clean();
        $this->assertFalse($result);
        restore_error_handler();
    }

    public function testS3Delete(): void
    {
        set_error_handler(static function (): bool { return true; });
        ob_start();
        $result = Storage::delete('test.txt');
        ob_end_clean();
        $this->assertIsBool($result);
        restore_error_handler();
    }

    public function testS3Url(): void
    {
        $url = Storage::url('test.txt');
        $this->assertIsString($url);
    }

    public function testS3FilesThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Storage::files('dir');
    }

    public function testS3Sign(): void
    {
        $cmd = new \ReflectionClass(Storage::class);
        $m = $cmd->getMethod('s3Sign');
        $m->setAccessible(true);
        $result = $m->invoke(null, 'GET', '/test.txt', ['Host: example.com'], '');
        $this->assertStringContainsString('AWS4-HMAC-SHA256', $result);
    }

    public function testS3SigningKey(): void
    {
        $cmd = new \ReflectionClass(Storage::class);
        $m = $cmd->getMethod('s3SigningKey');
        $m->setAccessible(true);
        $key = $m->invoke(null, 'secret', '20240101', 'us-east-1', 's3');
        $this->assertIsString($key);
    }
}
