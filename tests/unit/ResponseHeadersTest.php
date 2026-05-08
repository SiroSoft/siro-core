<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Response;

final class ResponseHeadersTest extends TestCase
{
    public function testHeaderReturnsSelf(): void
    {
        $response = Response::success();
        $result = $response->header('X-Custom-Header', 'value');
        $this->assertSame($response, $result);
    }

    public function testHeaderSetsSingleHeader(): void
    {
        $response = Response::success();
        $response->header('X-Custom-Header', 'value');
        $headers = $response->getHeaders();
        $this->assertContains('X-Custom-Header: value', $headers);
    }

    public function testHeaderOverridesExisting(): void
    {
        $response = Response::success();
        $response->header('X-Custom-Header', 'value1');
        $response->header('X-Custom-Header', 'value2');
        $headers = $response->getHeaders();
        $this->assertContains('X-Custom-Header: value2', $headers);
        $this->assertNotContains('X-Custom-Header: value1', $headers);
    }

    public function testWithHeadersReturnsSelf(): void
    {
        $response = Response::success();
        $result = $response->withHeaders(['X-Header-A' => 'value']);
        $this->assertSame($response, $result);
    }

    public function testWithHeadersSetsMultipleHeaders(): void
    {
        $response = Response::success();
        $response->withHeaders([
            'X-Header-A' => 'value-a',
            'X-Header-B' => 'value-b'
        ]);
        $headers = $response->getHeaders();
        $this->assertContains('X-Header-A: value-a', $headers);
        $this->assertContains('X-Header-B: value-b', $headers);
    }

    public function testWithHeadersMergesWithExisting(): void
    {
        $response = Response::success();
        $response->header('X-Header-A', 'value-a');
        $response->withHeaders(['X-Header-B' => 'value-b']);
        $headers = $response->getHeaders();
        $this->assertContains('X-Header-A: value-a', $headers);
        $this->assertContains('X-Header-B: value-b', $headers);
    }

    public function testChainedHeaderCalls(): void
    {
        $response = Response::success();
        $response
            ->header('X-Header-A', 'value-a')
            ->header('X-Header-B', 'value-b')
            ->header('X-Header-C', 'value-c');
        $headers = $response->getHeaders();
        $this->assertCount(3, $headers);
        $this->assertContains('X-Header-A: value-a', $headers);
        $this->assertContains('X-Header-B: value-b', $headers);
        $this->assertContains('X-Header-C: value-c', $headers);
    }

    public function testChainedWithHeadersCalls(): void
    {
        $response = Response::success();
        $response
            ->withHeaders(['X-Header-A' => 'a'])
            ->withHeaders(['X-Header-B' => 'b'])
            ->withHeaders(['X-Header-C' => 'c']);
        $headers = $response->getHeaders();
        $this->assertCount(3, $headers);
    }

    public function testHeaderWithSpecialCharacters(): void
    {
        $response = Response::success();
        $response->header('X-Custom-Header', 'value with spaces!@#$');
        $headers = $response->getHeaders();
        $this->assertContains('X-Custom-Header: value with spaces!@#$', $headers);
    }

    public function testHeaderOverrideViaWithHeaders(): void
    {
        $response = Response::success();
        $response->header('X-Header', 'original');
        $response->withHeaders(['X-Header' => 'override']);
        $headers = $response->getHeaders();
        $this->assertContains('X-Header: override', $headers);
        $this->assertNotContains('X-Header: original', $headers);
    }

    public function testMultipleWithHeadersMerge(): void
    {
        $response = Response::success();
        $response->withHeaders(['X-A' => '1', 'X-B' => '2']);
        $response->withHeaders(['X-C' => '3']);
        $response->withHeaders(['X-D' => '4']);
        $headers = $response->getHeaders();
        $this->assertCount(4, $headers);
    }

    public function testHeaderCaseSensitivity(): void
    {
        $response = Response::success();
        $response->header('x-custom-header', 'lowercase');
        $headers = $response->getHeaders();
        $this->assertContains('x-custom-header: lowercase', $headers);
    }

    public function testSuccessResponseHasDefaultStructure(): void
    {
        $response = Response::success(['key' => 'value'], 'Success Message');
        $headers = $response->getHeaders();
        $this->assertIsArray($headers);
    }

    public function testErrorResponseHeaders(): void
    {
        $response = Response::error('Error message', 400, ['field' => 'error']);
        $response->header('X-Error-Code', 'VALIDATION_ERROR');
        $headers = $response->getHeaders();
        $this->assertContains('X-Error-Code: VALIDATION_ERROR', $headers);
    }
}
