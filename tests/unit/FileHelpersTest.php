<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Storage;
use Siro\Core\UploadedFile;

/**
 * File Helpers Unit Tests
 */
final class FileHelpersTest extends TestCase
{
    public function testUploadedFileHasIsImage(): void
    {
        $this->assertTrue(method_exists(UploadedFile::class, 'isImage'));
    }

    public function testUploadedFileHasIsPdf(): void
    {
        $this->assertTrue(method_exists(UploadedFile::class, 'isPdf'));
    }

    public function testUploadedFileHasHash(): void
    {
        $this->assertTrue(method_exists(UploadedFile::class, 'hash'));
    }

    public function testUploadedFileHasExtension(): void
    {
        $this->assertTrue(method_exists(UploadedFile::class, 'extension'));
    }

    public function testUploadedFileHasName(): void
    {
        $this->assertTrue(method_exists(UploadedFile::class, 'name'));
    }

    public function testUploadedFileHasMaxSize(): void
    {
        $this->assertTrue(method_exists(UploadedFile::class, 'maxSize'));
    }

    public function testUploadedFileMaxSizeReturnsInt(): void
    {
        $maxSize = UploadedFile::maxSize();
        $this->assertIsInt($maxSize);
        $this->assertGreaterThan(0, $maxSize);
    }

    public function testStorageHasLocalPath(): void
    {
        $this->assertTrue(method_exists(Storage::class, 'localPath'));
    }

    public function testStorageHasLocalUrl(): void
    {
        $this->assertTrue(method_exists(Storage::class, 'localUrl'));
    }

    public function testStorageHasPutFile(): void
    {
        $this->assertTrue(method_exists(Storage::class, 'putFile'));
    }

    public function testStorageHasCopy(): void
    {
        $this->assertTrue(method_exists(Storage::class, 'copy'));
    }

    public function testStorageHasSize(): void
    {
        $this->assertTrue(method_exists(Storage::class, 'size'));
    }

    public function testStorageHasLastModified(): void
    {
        $this->assertTrue(method_exists(Storage::class, 'lastModified'));
    }

    public function testResponseHasDownloadFromStorage(): void
    {
        $this->assertTrue(method_exists(Response::class, 'downloadFromStorage'));
    }

    public function testResponseHasStream(): void
    {
        $this->assertTrue(method_exists(Response::class, 'stream'));
    }

    public function testRequestHasValidateFile(): void
    {
        $this->assertTrue(method_exists(Request::class, 'validateFile'));
    }
}