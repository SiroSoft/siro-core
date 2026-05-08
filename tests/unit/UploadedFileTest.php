<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\UploadedFile;

final class UploadedFileTest extends TestCase
{
    public function testGetClientOriginalName(): void
    {
        $file = new UploadedFile([
            'name' => 'document.pdf',
            'tmp_name' => '/tmp/upload',
            'type' => 'application/pdf',
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('document.pdf', $file->getClientOriginalName());
    }

    public function testGetClientOriginalExtension(): void
    {
        $file = new UploadedFile([
            'name' => 'image.jpeg',
            'tmp_name' => '/tmp/upload',
            'type' => 'image/jpeg',
            'size' => 2048,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('jpeg', $file->getClientOriginalExtension());
    }

    public function testGetSize(): void
    {
        $file = new UploadedFile([
            'name' => 'file.txt',
            'tmp_name' => '/tmp/upload',
            'type' => 'text/plain',
            'size' => 500,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame(500, $file->getSize());
    }

    public function testGetError(): void
    {
        $file = new UploadedFile([
            'name' => 'file.txt',
            'tmp_name' => '/tmp/upload',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->assertSame(UPLOAD_ERR_NO_FILE, $file->getError());
    }

    public function testGetPathname(): void
    {
        $file = new UploadedFile([
            'name' => 'file.txt',
            'tmp_name' => '/tmp/my_uploaded_file',
            'type' => 'text/plain',
            'size' => 100,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('/tmp/my_uploaded_file', $file->getPathname());
    }

    public function testIsValidReturnsFalseForNoFile(): void
    {
        $file = new UploadedFile([
            'name' => '',
            'tmp_name' => '',
            'type' => '',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->assertFalse($file->isValid());
    }

    public function testIsValidReturnsFalseForError(): void
    {
        $file = new UploadedFile([
            'name' => 'large.txt',
            'tmp_name' => '/tmp/upload',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_INI_SIZE,
        ]);

        $this->assertFalse($file->isValid());
    }

    public function testGetMimeTypeFallback(): void
    {
        $file = new UploadedFile([
            'name' => 'document.pdf',
            'tmp_name' => '/tmp/nonexistent',
            'type' => 'application/pdf',
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ]);

        $mime = $file->getMimeType();
        $this->assertSame('application/pdf', $mime);
    }

    public function testStoreThrowsForInvalidFile(): void
    {
        $file = new UploadedFile([
            'name' => 'file.txt',
            'tmp_name' => '',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);

        $this->expectException(\RuntimeException::class);
        $file->store('/tmp');
    }

    public function testGetClientOriginalExtensionUppercase(): void
    {
        $file = new UploadedFile([
            'name' => 'image.PNG',
            'tmp_name' => '/tmp/upload',
            'type' => 'image/png',
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('png', $file->getClientOriginalExtension());
    }

    public function testGetClientOriginalExtensionNoExtension(): void
    {
        $file = new UploadedFile([
            'name' => 'noextension',
            'tmp_name' => '/tmp/upload',
            'type' => 'application/octet-stream',
            'size' => 100,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('', $file->getClientOriginalExtension());
    }

    public function testGetClientOriginalExtensionMultipleDots(): void
    {
        $file = new UploadedFile([
            'name' => 'file.tar.gz',
            'tmp_name' => '/tmp/upload',
            'type' => 'application/gzip',
            'size' => 500,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('gz', $file->getClientOriginalExtension());
    }

    public function testGetClientOriginalNameJapanese(): void
    {
        $file = new UploadedFile([
            'name' => 'ファイル.pdf',
            'tmp_name' => '/tmp/upload',
            'type' => 'application/pdf',
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('ファイル.pdf', $file->getClientOriginalName());
    }

    public function testGetClientOriginalNameSpaces(): void
    {
        $file = new UploadedFile([
            'name' => 'my document.pdf',
            'tmp_name' => '/tmp/upload',
            'type' => 'application/pdf',
            'size' => 1024,
            'error' => UPLOAD_ERR_OK,
        ]);

        $this->assertSame('my document.pdf', $file->getClientOriginalName());
    }
}