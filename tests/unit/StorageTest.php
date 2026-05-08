<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Storage;

final class StorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    public function testFakeCreatesEmptyState(): void
    {
        Storage::fake();
        $this->assertFalse(Storage::exists('test.txt'));
    }

    public function testPutStoresContent(): void
    {
        Storage::put('test.txt', 'Hello World');
        Storage::assertExists('test.txt');
    }

    public function testGetRetrievesContent(): void
    {
        Storage::put('test.txt', 'Hello World');
        $this->assertSame('Hello World', Storage::get('test.txt'));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull(Storage::get('missing.txt'));
    }

    public function testExistsReturnsTrueForStored(): void
    {
        Storage::put('exists.txt', 'content');
        $this->assertTrue(Storage::exists('exists.txt'));
    }

    public function testExistsReturnsFalseForMissing(): void
    {
        $this->assertFalse(Storage::exists('missing.txt'));
    }

    public function testDeleteRemovesFile(): void
    {
        Storage::put('to-delete.txt', 'content');
        Storage::assertExists('to-delete.txt');
        Storage::delete('to-delete.txt');
        Storage::assertMissing('to-delete.txt');
    }

    public function testDeleteReturnsTrue(): void
    {
        Storage::put('file.txt', 'content');
        $result = Storage::delete('file.txt');
        $this->assertTrue($result);
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $result = Storage::delete('missing.txt');
        $this->assertFalse($result);
    }

    public function testMultiplePuts(): void
    {
        Storage::put('file1.txt', 'content1');
        Storage::put('file2.txt', 'content2');
        Storage::assertExists('file1.txt');
        Storage::assertExists('file2.txt');
        $this->assertSame('content1', Storage::get('file1.txt'));
        $this->assertSame('content2', Storage::get('file2.txt'));
    }

    public function testOverwriteFile(): void
    {
        Storage::put('file.txt', 'original');
        Storage::put('file.txt', 'updated');
        $this->assertSame('updated', Storage::get('file.txt'));
    }

    public function testDeleteRemovesOnlySpecifiedFile(): void
    {
        Storage::put('file1.txt', 'content1');
        Storage::put('file2.txt', 'content2');
        Storage::delete('file1.txt');
        Storage::assertMissing('file1.txt');
        Storage::assertExists('file2.txt');
    }

    public function testAssertExistsPasses(): void
    {
        Storage::put('pass.txt', 'content');
        Storage::assertExists('pass.txt');
        $this->assertTrue(true);
    }

    public function testAssertMissingPasses(): void
    {
        Storage::assertMissing('nonexistent.txt');
        $this->assertTrue(true);
    }

    public function testEmptyContent(): void
    {
        Storage::put('empty.txt', '');
        $this->assertSame('', Storage::get('empty.txt'));
        Storage::assertExists('empty.txt');
    }

    public function testBinaryContent(): void
    {
        $binary = "\x00\x01\x02\xFF\xFE\xFD";
        Storage::put('binary.dat', $binary);
        $this->assertSame($binary, Storage::get('binary.dat'));
    }

    public function testUnicodeContent(): void
    {
        $unicode = 'Hello 世界 🌍';
        Storage::put('unicode.txt', $unicode);
        $this->assertSame($unicode, Storage::get('unicode.txt'));
    }

    public function testLongContent(): void
    {
        $long = str_repeat('x', 100000);
        Storage::put('long.txt', $long);
        $this->assertSame($long, Storage::get('long.txt'));
    }

    public function testUrlReturnsPathForLocal(): void
    {
        Storage::put('file.txt', 'content');
        $url = Storage::url('file.txt');
        $this->assertStringContainsString('file.txt', $url);
    }

    public function testUrlWithSubdirectory(): void
    {
        Storage::put('dir1/dir2/file.txt', 'content');
        $url = Storage::url('dir1/dir2/file.txt');
        $this->assertStringContainsString('dir1/dir2/file.txt', $url);
    }
}
