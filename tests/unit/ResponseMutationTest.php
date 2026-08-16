<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Response;

/**
 * Branch coverage for Response: problem, download/stream/file, headers.
 */
final class ResponseMutationTest extends TestCase
{
    public function testProblem(): void
    {
        $r = Response::problem('Bad Request', 400, 'invalid_input', 'detail here');
        $this->assertSame(400, $r->statusCode());
    }

    public function testJson(): void
    {
        $r = Response::json(['a' => 1], 202);
        $this->assertSame(202, $r->statusCode());
    }

    public function testHeaderAndGetHeader(): void
    {
        $r = Response::success()->header('X-Custom', 'val');
        $this->assertSame('val', $r->getHeader('X-Custom'));
    }

    public function testIsFileResponse(): void
    {
        $r = Response::success();
        $this->assertFalse($r->isFileResponse());
    }

    public function testDownloadMissingFile(): void
    {
        $r = Response::download('/nonexistent/file.txt');
        $this->assertSame(404, $r->statusCode());
    }

    public function testDownloadExistingFile(): void
    {
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $tmp = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'resp_download.txt';
        if (!is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0775, true);
        }
        file_put_contents($tmp, 'hello');
        $r = Response::download($tmp, 'out.txt');
        $this->assertSame(200, $r->statusCode());
        @unlink($tmp);
    }

    public function testStreamExistingFile(): void
    {
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $tmp = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'resp_stream.bin';
        if (!is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0775, true);
        }
        file_put_contents($tmp, str_repeat('x', 20000));
        $r = Response::stream($tmp);
        $this->assertSame(200, $r->statusCode());
        @unlink($tmp);
    }

    public function testStreamMissingFile(): void
    {
        $r = Response::stream('/nonexistent/stream.bin');
        $this->assertSame(404, $r->statusCode());
    }

    public function testFileExisting(): void
    {
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $tmp = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'resp_file.txt';
        if (!is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0775, true);
        }
        file_put_contents($tmp, 'data');
        $r = Response::file($tmp, 'text/plain');
        $this->assertSame(200, $r->statusCode());
        @unlink($tmp);
    }

    public function testDownloadFromStorage(): void
    {
        $r = Response::downloadFromStorage('nonexistent/file');
        $this->assertContains($r->statusCode(), [404, 200]);
    }

    public function testRawContentType(): void
    {
        $r = Response::raw('<b>hi</b>', 'text/html', 201);
        $this->assertSame(201, $r->statusCode());
    }

    public function testSuccessWithMeta(): void
    {
        $r = Response::success(['x' => 1], 'OK', 200, ['page' => 2]);
        $this->assertSame(200, $r->statusCode());
    }

    public function testErrorWithErrorCode(): void
    {
        $r = Response::error('denied', 403, [], 'forbidden');
        $this->assertSame(403, $r->statusCode());
    }
}
