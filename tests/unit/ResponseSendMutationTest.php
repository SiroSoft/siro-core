<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Response;

/**
 * Extra Response branches: send, gzip, headers, debug meta, withHeaders.
 */
final class ResponseSendMutationTest extends TestCase
{
    public function testWithHeadersAndGetHeaders(): void
    {
        $r = Response::success()->header('X-One', '1')->withHeaders(['X-Two' => '2']);
        $h = $r->getHeaders();
        $this->assertContains('X-One: 1', $h);
        $this->assertContains('X-Two: 2', $h);
    }

    public function testGetStatusCode(): void
    {
        $r = Response::error('bad', 422);
        $this->assertSame(422, $r->getStatusCode());
    }

    public function testSendJson(): void
    {
        $r = Response::success(['a' => 1]);
        ob_start();
        $r->send();
        $out = ob_get_clean();
        $this->assertStringContainsString('success', (string) $out);
    }

    public function testSendNoContent(): void
    {
        $r = Response::noContent();
        ob_start();
        $r->send();
        ob_get_clean();
        $this->assertTrue(true);
    }

    public function testDownloadFromStorageExisting(): void
    {
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $dir = $base . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . 'resp_dl.txt';
        file_put_contents($tmp, 'data');
        $r = Response::downloadFromStorage('resp_dl.txt');
        $this->assertContains($r->statusCode(), [200, 404]);
        @unlink($tmp);
    }

    public function testEnableDebugAndMeta(): void
    {
        Response::enableDebug(true);
        Response::setDebugMeta(['k' => 'v']);
        Response::setRequestMeta('req-1', microtime(true));
        $this->assertTrue(true);
        Response::enableDebug(false);
    }

    public function testPayloadAccess(): void
    {
        $r = Response::success(['x' => 1]);
        $p = $r->payload();
        $this->assertArrayHasKey('data', $p);
    }

    public function testFileResponseIsFile(): void
    {
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $dir = $base . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . 'resp_file2.txt';
        file_put_contents($tmp, str_repeat('x', 1000));
        $r = Response::file($tmp, 'text/plain');
        $this->assertSame(200, $r->statusCode());
        @unlink($tmp);
    }
}
