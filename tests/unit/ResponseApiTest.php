<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Response;
use Siro\Core\Storage;

/**
 * Extended Response API tests — covers problem, download, stream, file,
 * headers, payload, redirect and send() output.
 */
final class ResponseApiTest extends TestCase
{
    private string $projTmp;

    protected function setUp(): void
    {
        parent::setUp();
        // sanitizeDownloadPath requires files within the project (getcwd) dir
        $this->projTmp = dirname(__DIR__, 2) . '/storage/test_resp_' . uniqid();
        if (!is_dir($this->projTmp)) {
            mkdir($this->projTmp, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projTmp)) {
            foreach (glob($this->projTmp . '/*') ?: [] as $f) {
                if (is_file($f)) @unlink($f);
            }
            @rmdir($this->projTmp);
        }
        parent::tearDown();
    }

    private function makeProjectFile(string $name, string $content): string
    {
        $path = $this->projTmp . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    public function testProblemCreatesRFC7807Payload(): void
    {
        $resp = Response::problem('Not Found', 404, 'Resource missing', 'https://errors.dev/notfound', 'req-123');
        $this->assertSame(404, $resp->statusCode());
        $payload = $resp->payload();
        $this->assertSame('Not Found', $payload['title']);
        $this->assertSame(404, $payload['status']);
        $this->assertSame('Resource missing', $payload['detail']);
        $this->assertSame('https://errors.dev/notfound', $payload['type']);
        $this->assertSame('req-123', $payload['instance']);
    }

    public function testProblemWithoutInstanceOmitsIt(): void
    {
        $resp = Response::problem('Error');
        $this->assertArrayNotHasKey('instance', $resp->payload());
    }

    public function testRedirectValid(): void
    {
        $resp = Response::redirect('/login');
        $this->assertSame(302, $resp->statusCode());
        $this->assertSame('/login', $resp->getHeader('Location'));
    }

    public function testRedirectCustomStatus(): void
    {
        $resp = Response::redirect('/moved', 301);
        $this->assertSame(301, $resp->statusCode());
        $this->assertSame('/moved', $resp->getHeader('Location'));
    }

    public function testRedirectWithoutHostFallsBackToRoot(): void
    {
        // A malformed URL (no host) falls back to '/'
        $resp = Response::redirect('not a url');
        $this->assertSame('/', $resp->getHeader('Location'));
    }

    public function testDownloadSetsFileResponseHeaders(): void
    {
        $file = $this->makeProjectFile('report.txt', 'download content');
        $resp = Response::download($file, 'report.txt', ['X-Custom' => 'yes']);
        $this->assertTrue($resp->isFileResponse());
        $this->assertSame('attachment; filename="report.txt"', $resp->getHeader('Content-Disposition'));
        $this->assertSame('yes', $resp->getHeader('X-Custom'));
        $this->assertNotNull($resp->getHeader('Content-Length'));
        $this->assertSame(200, $resp->statusCode());
    }

    public function testDownloadMissingFileReturnsError(): void
    {
        $resp = Response::download($this->projTmp . '/definitely-missing.txt');
        $this->assertSame(404, $resp->statusCode());
        $this->assertFalse($resp->isFileResponse());
    }

    public function testDownloadPathTraversalRejected(): void
    {
        $resp = Response::download('../etc/passwd');
        $this->assertSame(404, $resp->statusCode());
        $this->assertFalse($resp->isFileResponse());
    }

    public function testDownloadFromStorageMissingReturns404(): void
    {
        Storage::fake();
        $resp = Response::downloadFromStorage('missing-file-in-storage.txt');
        $this->assertSame(404, $resp->statusCode());
    }

    public function testStreamFile(): void
    {
        $file = $this->makeProjectFile('data.bin', 'stream payload content');
        $resp = Response::stream($file, 'data.bin');
        $this->assertTrue($resp->isFileResponse());
        $this->assertSame('attachment; filename="data.bin"', $resp->getHeader('Content-Disposition'));
    }

    public function testFileResponse(): void
    {
        $file = $this->makeProjectFile('data.json', '{"a":1}');
        $resp = Response::file($file, 'application/json', ['X-File' => '1']);
        $this->assertTrue($resp->isFileResponse());
        $this->assertSame('1', $resp->getHeader('X-File'));
    }

    public function testHeaderAndWithHeaders(): void
    {
        $resp = Response::success([]);
        $resp->header('X-One', '1');
        $resp->withHeaders(['X-Two' => '2', 'X-Three' => '3']);
        $this->assertSame('1', $resp->getHeader('X-One'));
        $this->assertSame('2', $resp->getHeader('X-Two'));
        $this->assertSame('3', $resp->getHeader('X-Three'));
    }

    public function testGetHeadersFormats(): void
    {
        $resp = Response::success([])->withHeaders(['X-A' => 'a', 'X-B' => 'b']);
        $headers = $resp->getHeaders();
        $this->assertContains('X-A: a', $headers);
        $this->assertContains('X-B: b', $headers);
    }

    public function testPayloadAndStatusCode(): void
    {
        $resp = Response::success(['id' => 1], 'OK');
        $this->assertTrue($resp->payload()['success']);
        $this->assertSame('OK', $resp->payload()['message']);
        $this->assertSame(['id' => 1], $resp->payload()['data']);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testSuccessWithMeta(): void
    {
        $resp = Response::success(['x' => 1], 'OK', 200, ['page' => 2]);
        $this->assertSame(2, $resp->payload()['meta']['page']);
    }

    public function testErrorWithErrorsAndCode(): void
    {
        $resp = Response::error('Invalid', 422, ['email' => ['bad format']], 'VAL_001');
        $this->assertSame(422, $resp->statusCode());
        $this->assertSame(['email' => ['bad format']], $resp->payload()['meta']['errors']);
        $this->assertSame('VAL_001', $resp->payload()['meta']['error_code']);
    }

    public function testPaginatedStructure(): void
    {
        $resp = Response::paginated([['id' => 1]], ['total' => 1, 'page' => 1], 'List');
        $payload = $resp->payload();
        $this->assertSame([['id' => 1]], $payload['data']);
        $this->assertSame(1, $payload['meta']['total']);
    }

    public function testJsonResponse(): void
    {
        $resp = Response::json(['a' => 1], 201);
        $this->assertSame(201, $resp->statusCode());
        $this->assertSame(['a' => 1], $resp->payload());
    }

    public function testSendOutputsJsonInCli(): void
    {
        $resp = Response::success(['ok' => true]);
        ob_start();
        $resp->send();
        $output = ob_get_clean() ?: '';
        $this->assertStringContainsString('"success":true', $output);
        $this->assertStringContainsString('"ok":true', $output);
    }
}
