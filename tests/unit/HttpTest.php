<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Http;
use Siro\Core\Response as HttpResponse;

final class HttpTest extends TestCase
{
    private string $testServerUrl = 'http://httpbin.org';

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('HTTP tests require external network/SSL connectivity');
    }

    private function hasInternetConnection(): bool
    {
        return @fsockopen('httpbin.org', 80, $errno, $errstr, 2) !== false;
    }

    public function testGetRequest(): void
    {
        $response = Http::get($this->testServerUrl . '/get');

        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertEquals(200, $response->status());
        $this->assertNotEmpty($response->body());
    }

    public function testGetRequestWithQueryParams(): void
    {
        $response = Http::get($this->testServerUrl . '/get', [
            'foo' => 'bar',
            'baz' => 'qux'
        ]);

        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertEquals('bar', $data['args']['foo']);
        $this->assertEquals('qux', $data['args']['baz']);
    }

    public function testPostRequestWithJsonBody(): void
    {
        $payload = [
            'name' => 'John Doe',
            'email' => 'john@example.com'
        ];

        $response = Http::post($this->testServerUrl . '/post', $payload);

        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertEquals('John Doe', $data['json']['name']);
        $this->assertEquals('john@example.com', $data['json']['email']);
    }

    public function testPutRequest(): void
    {
        $payload = ['updated' => true];
        $response = Http::put($this->testServerUrl . '/put', $payload);

        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertTrue($data['json']['updated']);
    }

    public function testPatchRequest(): void
    {
        $payload = ['patched' => true];
        $response = Http::patch($this->testServerUrl . '/patch', $payload);

        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertTrue($data['json']['patched']);
    }

    public function testDeleteRequest(): void
    {
        $response = Http::delete($this->testServerUrl . '/delete');

        $this->assertEquals(200, $response->status());
    }

    public function testCustomHeaders(): void
    {
        Http::withHeaders([
            'X-Custom-Header' => 'test-value',
            'Accept' => 'application/json'
        ]);

        $response = Http::get($this->testServerUrl . '/headers');

        // Reset headers after test
        Http::withHeaders([]);

        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertEquals('test-value', $data['headers']['X-Custom-Header']);
    }

    public function testResponseOkMethod(): void
    {
        $response = Http::get($this->testServerUrl . '/get');
        $this->assertTrue($response->ok());
    }

    public function testResponseStatusMethod(): void
    {
        $response = Http::get($this->testServerUrl . '/get');
        $this->assertEquals(200, $response->status());
    }

    public function testResponseBodyMethod(): void
    {
        $response = Http::get($this->testServerUrl . '/get');
        $this->assertIsString($response->body());
        $this->assertNotEmpty($response->body());
    }

    public function testResponseJsonMethod(): void
    {
        $response = Http::get($this->testServerUrl . '/get');
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('url', $data);
    }

    public function testResponseHeadersMethod(): void
    {
        $response = Http::get($this->testServerUrl . '/get');
        $headers = $response->headers();
        $this->assertIsArray($headers);
        $this->assertNotEmpty($headers);
    }

    public function testErrorResponse404(): void
    {
        $response = Http::get($this->testServerUrl . '/status/404');
        $this->assertEquals(404, $response->status());
        $this->assertFalse($response->ok());
    }

    public function testErrorResponse500(): void
    {
        $response = Http::get($this->testServerUrl . '/status/500');
        $this->assertEquals(500, $response->status());
        $this->assertFalse($response->ok());
    }

    public function testTimeoutHandling(): void
    {
        Http::timeout(2);
        $start = microtime(true);

        try {
            $response = Http::get($this->testServerUrl . '/delay/5');
        } catch (\RuntimeException $e) {
            // Expected timeout
        }

        $elapsed = microtime(true) - $start;
        Http::timeout(30); // Reset timeout

        // Should timeout after ~2 seconds, not wait full 5
        $this->assertLessThan(4, $elapsed);
    }

    public function testLargeResponse(): void
    {
        $response = Http::get($this->testServerUrl . '/bytes/1048576'); // 1MB
        $this->assertEquals(200, $response->status());
        $this->assertEquals(1048576, strlen($response->body()));
    }

    public function testRedirectFollowing(): void
    {
        $response = Http::get($this->testServerUrl . '/redirect/1');
        $this->assertEquals(200, $response->status());
    }

    public function testBasicAuth(): void
    {
        $authHeader = 'Authorization: Basic ' . base64_encode('user:passwd');
        Http::withHeaders([$authHeader]);

        $response = Http::get($this->testServerUrl . '/basic-auth/user/passwd');

        Http::withHeaders([]); // Reset
        $this->assertEquals(200, $response->status());
    }

    public function testBearerToken(): void
    {
        Http::withHeaders(['Authorization' => 'Bearer test-token']);

        $response = Http::get($this->testServerUrl . '/headers');

        Http::withHeaders([]); // Reset
        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertStringContainsString('Bearer test-token', $data['headers']['Authorization'] ?? '');
    }

    public function testFormUrlEncoded(): void
    {
        Http::withHeaders(['Content-Type' => 'application/x-www-form-urlencoded']);

        $response = Http::post($this->testServerUrl . '/post', [
            'field1' => 'value1',
            'field2' => 'value2'
        ]);

        Http::withHeaders([]); // Reset
        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertEquals('value1', $data['form']['field1']);
    }

    public function testMultipartFormData(): void
    {
        // Skip multipart test as Http class doesn't support file attachments yet
        $this->markTestSkipped('Multipart form data not supported in current Http implementation');
    }

    public function testRetryMechanism(): void
    {
        // Manual retry logic since Http doesn't have built-in retry
        $maxRetries = 3;
        $response = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $response = Http::get($this->testServerUrl . '/status/500');
                break;
            } catch (\RuntimeException $e) {
                if ($i === $maxRetries - 1) throw $e;
                usleep(100000); // 100ms delay
            }
        }

        $this->assertEquals(500, $response->status());
    }

    public function testUserAgent(): void
    {
        Http::withHeaders(['User-Agent' => 'SiroPHP-Test/1.0']);

        $response = Http::get($this->testServerUrl . '/headers');

        Http::withHeaders([]); // Reset
        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertEquals('SiroPHP-Test/1.0', $data['headers']['User-Agent']);
    }

    public function testContentType(): void
    {
        Http::withHeaders(['Content-Type' => 'application/xml']);

        $response = Http::get($this->testServerUrl . '/headers');

        Http::withHeaders([]); // Reset
        $this->assertEquals(200, $response->status());
    }

    public function testAcceptHeader(): void
    {
        Http::withHeaders(['Accept' => 'application/json']);

        $response = Http::get($this->testServerUrl . '/headers');

        Http::withHeaders([]); // Reset
        $this->assertEquals(200, $response->status());
        $data = $response->json();
        $this->assertStringContainsString('application/json', $data['headers']['Accept']);
    }

    public function testEmptyResponse(): void
    {
        $response = Http::get($this->testServerUrl . '/status/204');
        $this->assertEquals(204, $response->status());
        $this->assertEmpty($response->body());
    }

    public function testInvalidUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        Http::get('http://invalid.domain.that.does.not.exist/test');
    }

    public function testConnectionRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        Http::timeout(2)->get('http://localhost:99999/test');
    }

    public function testSSLError(): void
    {
        // This should fail with SSL error on https with invalid cert
        $response = Http::get('https://expired.badssl.com/');
        $this->assertFalse($response->ok());
    }

    public function testConcurrentRequests(): void
    {
        $responses = [];
        $urls = [
            $this->testServerUrl . '/get?n=1',
            $this->testServerUrl . '/get?n=2',
            $this->testServerUrl . '/get?n=3'
        ];

        foreach ($urls as $url) {
            $responses[] = Http::get($url);
        }

        $this->assertCount(3, $responses);
        foreach ($responses as $response) {
            $this->assertEquals(200, $response->status());
        }
    }

    public function testChainedMethods(): void
    {
        Http::timeout(5);
        Http::withHeaders([
            'X-Test' => 'value',
            'Authorization' => 'Bearer token123'
        ]);

        $response = Http::get($this->testServerUrl . '/headers');

        // Reset
        Http::timeout(30);
        Http::withHeaders([]);

        $this->assertEquals(200, $response->status());
    }

    public function testPerformanceMultipleRequests(): void
    {
        $start = microtime(true);

        for ($i = 0; $i < 10; $i++) {
            Http::get($this->testServerUrl . '/get');
        }

        $elapsed = microtime(true) - $start;
        // Should complete 10 requests in reasonable time (<30s)
        $this->assertLessThan(30, $elapsed);
    }
}
