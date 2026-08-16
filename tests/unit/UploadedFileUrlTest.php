<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\UploadedFile;
use Siro\Core\URL;

/**
 * UploadedFile + URL signed-route tests.
 */
final class UploadedFileUrlTest extends TestCase
{
    public function testConstructorAndAccessors(): void
    {
        $f = new UploadedFile([
            'tmp_name' => '/tmp/upload.tmp',
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
            'size' => 1234,
            'error' => UPLOAD_ERR_OK,
        ]);
        $this->assertSame('/tmp/upload.tmp', $f->getPathname());
        $this->assertSame('photo.jpg', $f->getClientOriginalName());
        $this->assertSame('jpg', $f->getClientOriginalExtension());
        $this->assertSame(1234, $f->getSize());
        $this->assertSame(UPLOAD_ERR_OK, $f->getError());
        $this->assertFalse($f->isValid(), 'not a real upload so invalid');
        $this->assertNotEmpty($f->name());
    }

    public function testMimeTypeFallbackWhenNotUploaded(): void
    {
        $f = new UploadedFile([
            'tmp_name' => '',
            'name' => 'doc.txt',
            'type' => 'text/plain',
            'size' => 10,
            'error' => UPLOAD_ERR_OK,
        ]);
        $this->assertSame('text/plain', $f->getMimeType());
    }

    public function testGetErrorForNoFile(): void
    {
        $f = new UploadedFile([]);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $f->getError());
        $this->assertFalse($f->isValid());
    }

    public function testExtensionDetection(): void
    {
        $f = new UploadedFile(['name' => 'REPORT.PDF', 'tmp_name' => '', 'type' => 'application/pdf', 'size' => 1, 'error' => 0]);
        $this->assertSame('pdf', $f->extension());
        // isPdf/isImage require a real upload (is_uploaded_file) — not testable here
        $this->assertFalse($f->isPdf());
    }

    public function testInvalidFileCannotStore(): void
    {
        $f = new UploadedFile(['tmp_name' => '', 'name' => 'x.txt', 'type' => 'text/plain', 'size' => 1, 'error' => 0]);
        $this->expectException(\RuntimeException::class);
        $f->store(sys_get_temp_dir(), 'x.txt');
    }

    public function testInvalidFileHashIsNull(): void
    {
        $f = new UploadedFile(['tmp_name' => '', 'name' => 'x.txt', 'type' => 'text/plain', 'size' => 1, 'error' => 0]);
        $this->assertNull($f->hash());
    }

    public function testUrlSignedAndValidate(): void
    {
        $signed = URL::signed('/api/verify', ['id' => 5], 3600);
        $this->assertStringContainsString('/api/verify', $signed);
        $this->assertStringContainsString('signature=', $signed);

        // Extract payload + signature from the signed URL query
        $qs = parse_url($signed, PHP_URL_QUERY) ?: '';
        parse_str($qs, $parts);
        $payload = $parts['payload'] ?? '';
        $signature = $parts['signature'] ?? '';
        $this->assertNotSame('', $payload);
        $this->assertNotSame('', $signature);

        $data = URL::validate($payload, $signature);
        $this->assertNotNull($data);
        $this->assertSame('5', (string) $data['params']['id']);
        $this->assertSame('/api/verify', $data['route']);
    }

    public function testUrlValidateWithBadSignature(): void
    {
        $this->assertNull(URL::validate('/api/x', 'badsig'));
    }
}
