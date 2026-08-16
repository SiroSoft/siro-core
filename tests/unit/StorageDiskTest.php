<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Storage;

/**
 * Storage real-disk tests (local driver) — covers put/get/delete/copy/size/
 * lastModified/files/putFile/url/localPath. Uses a temp dir inside the project
 * so the path-traversal guard (project-root bound) accepts it.
 */
final class StorageDiskTest extends TestCase
{
    private string $storageDir;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = dirname(__DIR__, 2);
        $this->storageDir = 'storage/test_storage_' . uniqid();
        $full = $this->base . '/' . $this->storageDir;
        if (!is_dir($full)) {
            mkdir($full, 0777, true);
        }
        putenv('STORAGE_DRIVER=local');
        putenv('STORAGE_PATH=' . $this->storageDir);
        Storage::reset();
        Storage::boot();
    }

    protected function tearDown(): void
    {
        $full = $this->base . '/' . $this->storageDir;
        if (is_dir($full)) {
            $this->removeDir($full);
        }
        putenv('STORAGE_PATH');
        Storage::reset();
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_dir($f)) {
                $this->removeDir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    public function testPutAndGetRealDisk(): void
    {
        $ok = Storage::put('disk.txt', 'hello disk');
        $this->assertTrue($ok);
        $this->assertSame('hello disk', Storage::get('disk.txt'));
        Storage::delete('disk.txt');
    }

    public function testPutCreatesNestedDirs(): void
    {
        Storage::put('nested/deep/file.txt', 'nested');
        $this->assertTrue(Storage::exists('nested/deep/file.txt'));
        Storage::delete('nested/deep/file.txt');
        $this->assertFalse(Storage::exists('nested/deep/file.txt'));
    }

    public function testExistsRealDisk(): void
    {
        $this->assertFalse(Storage::exists('missing.txt'));
        Storage::put('present.txt', 'x');
        $this->assertTrue(Storage::exists('present.txt'));
        Storage::delete('present.txt');
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $this->assertFalse(Storage::delete('not-there.txt'));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull(Storage::get('missing.txt'));
    }

    public function testCopy(): void
    {
        Storage::put('src.txt', 'copy me');
        $ok = Storage::copy('src.txt', 'dst.txt');
        $this->assertTrue($ok);
        $this->assertSame('copy me', Storage::get('dst.txt'));
        Storage::delete('src.txt');
        Storage::delete('dst.txt');
    }

    public function testSize(): void
    {
        Storage::put('size.txt', '12345');
        $this->assertSame(5, Storage::size('size.txt'));
        Storage::delete('size.txt');
    }

    public function testLastModified(): void
    {
        Storage::put('mtime.txt', 'x');
        $ts = Storage::lastModified('mtime.txt');
        $this->assertGreaterThan(0, $ts);
        Storage::delete('mtime.txt');
    }

    public function testFilesListsDirectory(): void
    {
        Storage::put('list_a.txt', 'a');
        Storage::put('list_b.txt', 'b');
        $files = Storage::files('');
        $names = array_map(fn (string $f): string => basename($f), $files);
        $this->assertContains('list_a.txt', $names);
        $this->assertContains('list_b.txt', $names);
        Storage::delete('list_a.txt');
        Storage::delete('list_b.txt');
    }

    public function testPutFile(): void
    {
        $filename = Storage::putFile('uploads', 'binary content', 'file.dat');
        $this->assertStringContainsString('file.dat', $filename);
        $this->assertTrue(Storage::exists($filename));
        Storage::delete($filename);
    }

    public function testLocalPathResolvesInsideStorage(): void
    {
        $path = Storage::localPath('some/file.txt');
        $expected = str_replace('/', DIRECTORY_SEPARATOR, $this->storageDir);
        $this->assertStringContainsString($expected, $path);
        $this->assertStringEndsWith('file.txt', $path);
    }

    public function testUrl(): void
    {
        $url = Storage::url('asset.png');
        $this->assertStringContainsString('asset.png', $url);
    }

    public function testPathTraversalRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        Storage::localPath('../outside');
    }
}
