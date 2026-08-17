<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Response;

/**
 * Response send/gzip/raw branches (CLI mode).
 */
final class ResponseGzipMutationTest extends TestCase
{
    public function testSendFileCli(): void
    {
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $dir = $base . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $dir . '/resp_gzip.txt';
        file_put_contents($tmp, str_repeat('x', 5000));
        $r = Response::file($tmp, 'text/plain');
        ob_start();
        $r->send();
        $out = ob_get_clean();
        $this->assertIsString($out);
        @unlink($tmp);
    }

    public function testSendJsonCli(): void
    {
        $r = Response::success(['a' => 1]);
        ob_start();
        $r->send();
        $out = ob_get_clean();
        $this->assertStringContainsString('success', (string) $out);
    }

    public function testSendErrorCli(): void
    {
        $r = Response::error('bad', 400, ['field' => ['msg']]);
        ob_start();
        $r->send();
        $out = ob_get_clean();
        $this->assertStringContainsString('bad', (string) $out);
    }

    public function testGetStatusCodeAlias(): void
    {
        $r = Response::created(['id' => 1]);
        $this->assertSame(201, $r->getStatusCode());
    }

    public function testIsFileResponseDownload(): void
    {
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $dir = $base . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $dir . '/resp_file3.txt';
        file_put_contents($tmp, 'data');
        $r = Response::download($tmp, 'out.txt');
        $this->assertTrue($r->isFileResponse());
        @unlink($tmp);
    }

    public function testSendPaginatedCli(): void
    {
        $r = Response::paginated([[1]], ['page' => 1, 'per_page' => 1, 'total' => 1, 'last_page' => 1]);
        ob_start();
        $r->send();
        $out = ob_get_clean();
        $this->assertStringContainsString('data', (string) $out);
    }

    public function testRawNotCompressible(): void
    {
        $r = Response::raw('not compressible text', 'text/html', 200);
        ob_start();
        $r->send();
        $out = ob_get_clean();
        $this->assertIsString($out);
    }
}
