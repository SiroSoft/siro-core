<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;
use Siro\Core\Storage;
use Siro\Core\UploadedFile;

/**
 * UploadedFile additional coverage: pathname, hash, extension, name,
 * store via Storage facade, storeAs.
 */
final class UploadedFileEdgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('APP_URL=http://localhost');
        Storage::fake();
    }

    protected function tearDown(): void
    {
        putenv('APP_URL');
        parent::tearDown();
    }

    private function makeFile(string $content = 'file content', string $name = 'file.txt', string $mime = 'text/plain'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl_');
        file_put_contents($tmp, $content);
        return new UploadedFile([
            'tmp_name' => $tmp,
            'name' => $name,
            'type' => $mime,
            'size' => strlen($content),
            'error' => UPLOAD_ERR_OK,
        ]);
    }

    public function testGetPathname(): void
    {
        $file = $this->makeFile();
        $this->assertSame($file->getPathname(), realpath($file->getPathname()));
    }

    public function testHashWithInvalidFile(): void
    {
        $file = new UploadedFile([
            'tmp_name' => '',
            'name' => 'x.txt',
            'type' => 'text/plain',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ]);
        $this->assertNull($file->hash());
    }

    public function testExtensionAndName(): void
    {
        $file = $this->makeFile('x', 'report.pdf', 'application/pdf');
        $this->assertSame('pdf', $file->extension());
        $this->assertSame('report', $file->name());
    }

    public function testStoreWithUseStorageThrowsOnInvalid(): void
    {
        $file = $this->makeFile('uploaded data', 'data.txt', 'text/plain');
        $this->expectException(\RuntimeException::class);
        $file->store('docs', null, true);
    }

    public function testStoreAsThrowsOnInvalid(): void
    {
        $file = $this->makeFile('content here', 'original.txt', 'text/plain');
        $this->expectException(\RuntimeException::class);
        $file->storeAs('uploads', 'renamed.txt');
    }
}
