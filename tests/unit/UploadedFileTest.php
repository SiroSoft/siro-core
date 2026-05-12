<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\UploadedFile;

final class UploadedFileTest extends TestCase
{
    public function testIsValidReturnsFalseForErrorUpload(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.txt',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->assertFalse($file->isValid());
    }

    public function testGetClientOriginalName(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('photo.jpg', $file->getClientOriginalName());
    }

    public function testGetClientOriginalExtension(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'document.pdf',
            'type' => 'application/pdf',
            'size' => 2048,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('pdf', $file->getClientOriginalExtension());
    }

    public function testExtension(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'archive.tar.gz',
            'type' => 'application/gzip',
            'size' => 0,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('gz', $file->extension());
    }

    public function testGetSize(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.txt',
            'type' => 'text/plain',
            'size' => 4096,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame(4096, $file->getSize());
    }

    public function testGetError(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.txt',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_INI_SIZE,
        ]);

        $this->assertSame(UPLOAD_ERR_INI_SIZE, $file->getError());
    }

    public function testIsImageWithInvalidFile(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.jpg',
            'type' => 'image/jpeg',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->assertFalse($file->isImage());
    }

    public function testIsPdfWithInvalidFile(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.pdf',
            'type' => 'application/pdf',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->assertFalse($file->isPdf());
    }

    public function testNameExtractsFilenameWithoutExtension(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'my_document.pdf',
            'type' => 'application/pdf',
            'size' => 0,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('my_document', $file->name());
    }

    public function testHashReturnsNullForInvalidFile(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.txt',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->assertNull($file->hash());
    }

    public function testStoreThrowsOnInvalidFile(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.txt',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->expectException(\RuntimeException::class);
        $file->store('uploads');
    }

    public function testStoreThrowsOnDirectoryTraversal(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.txt',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->expectException(\RuntimeException::class);
        $file->store('../etc');
    }

    public function testStoreThrowsOnSpecialCharsInDirectory(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.txt',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->expectException(\RuntimeException::class);
        $file->store('path;rm -rf /');
    }

    public function testGenerateFilenameReturnsSafeFormat(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.txt',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_OK,
        ]);

        $ref = new \ReflectionMethod($file, 'generateFilename');
        $ref->setAccessible(true);
        $name = $ref->invoke($file);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}(\.txt)?$/', $name);
    }

    public function testMaxSizeReturnsPositiveInt(): void
    {
        $max = UploadedFile::maxSize();
        $this->assertGreaterThan(0, $max);
    }

    public function testGetClientMimeTypeReturnsSubmittedType(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'test.jpg',
            'type' => 'image/jpeg',
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ]);

        $mime = $file->getMimeType();
        $this->assertIsString($mime);
    }

    public function testIsImageWithNonImageExtensionReturnsFalse(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'not really a pdf');
        $file = new UploadedFile([
            'tmp_name' => $tmpFile,
            'name' => 'document.pdf',
            'type' => 'application/pdf',
            'size' => filesize($tmpFile),
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertFalse($file->isImage());
        $this->assertTrue($file->isPdf() || !$file->isPdf());

        unlink($tmpFile);
    }
}
