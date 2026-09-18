<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Http;

final class HttpEncodeBodyTest extends TestCase
{
    /**
     * Invoke the private Http::encodeBody() so the payload-encoding rules can
     * be verified offline without external network/SSL connectivity.
     */
    private function encodeBody(mixed $data, array $headers): mixed
    {
        $method = new \ReflectionMethod(Http::class, 'encodeBody');
        $method->setAccessible(true);
        return $method->invoke(null, $data, $headers);
    }

    public function testArrayWithoutContentTypeUsesFormUrlEncoded(): void
    {
        $body = $this->encodeBody(['a' => '1', 'b' => 'hello world'], []);
        $this->assertSame('a=1&b=hello+world', $body);
    }

    public function testFormUrlEncodedContentTypeUsesHttpBuildQuery(): void
    {
        $body = $this->encodeBody(
            ['secret' => 's3cr3t', 'response' => 'tok.en_x'],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        );
        // Regression: an explicit form Content-Type must NOT be JSON-encoded,
        // otherwise Cloudflare siteverify rejects it with missing-input-secret.
        $this->assertSame('secret=s3cr3t&response=tok.en_x', $body);
    }

    public function testJsonContentTypeProducesJson(): void
    {
        $body = $this->encodeBody(
            ['name' => 'John', 'email' => 'john@example.com'],
            ['Content-Type' => 'application/json'],
        );
        $this->assertSame('{"name":"John","email":"john@example.com"}', $body);
    }

    public function testNonStringDataPassedThroughUnchanged(): void
    {
        $this->assertSame('raw-body', $this->encodeBody('raw-body', []));
        $this->assertNull($this->encodeBody(null, []));
        $this->assertSame(123, $this->encodeBody(123, []));
    }
}
