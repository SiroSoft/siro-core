<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use InvalidArgumentException;
use Siro\Core\Response;
use Siro\Core\Tests\TestCase;

/**
 * P1-6: Response::raw() must respect an explicitly passed content type
 * (only sniff when the caller passes none), and the JSON, raw and file
 * branches must all send the same security headers (nosniff, DENY,
 * CSP with frame-ancestors 'none'), unless the caller overrides one.
 *
 * P2-2 (TRACKER numbering; proposal P2-1): the Response constructor
 * guards against out-of-range status codes with a clear message.
 *
 * Note: tests run under CLI, where header() is a no-op — so the merged
 * security headers are asserted via headers()/getHeaders() after send(),
 * which is exactly where applySecurityHeaders() puts them.
 */
final class ResponseRawSecurityTest extends TestCase
{
    private function sendAndCapture(Response $response): string
    {
        ob_start();
        $response->send();
        $out = ob_get_clean();
        return is_string($out) ? $out : '';
    }

    /** @return array<string, string> */
    private function sentHeaders(Response $response): array
    {
        $this->sendAndCapture($response);
        return $response->headers();
    }

    public function testExplicitTextPlainIsRespected(): void
    {
        $response = Response::raw('<b>x</b>', 'text/plain');
        $this->assertSame('text/plain', $response->getHeader('Content-Type'));
        $headers = $this->sentHeaders($response);
        $this->assertSame('text/plain', $headers['Content-Type']);
    }

    public function testExplicitNullSniffsLikeNoArgument(): void
    {
        $response = Response::raw('<b>x</b>', null);
        $this->assertSame('text/html', $response->getHeader('Content-Type'));
    }

    public function testNoContentTypeSniffsHtml(): void
    {
        $response = Response::raw('<b>x</b>');
        $this->assertSame('text/html', $response->getHeader('Content-Type'));
    }

    public function testNoContentTypeSniffsJson(): void
    {
        $response = Response::raw('{"a":1}');
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
    }

    public function testNoContentTypeDefaultsToTextPlain(): void
    {
        $response = Response::raw('hello');
        $this->assertSame('text/plain', $response->getHeader('Content-Type'));
    }

    public function testJsonBranchHasSecurityHeaders(): void
    {
        $headers = $this->sentHeaders(Response::json(['ok' => true]));
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
    }

    public function testRawBranchHasSecurityHeaders(): void
    {
        $headers = $this->sentHeaders(Response::raw('<b>x</b>'));
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
    }

    public function testFileBranchHasSecurityHeaders(): void
    {
        $headers = $this->sentHeaders(Response::file('composer.json'));
        $this->assertSame('inline', $headers['Content-Disposition']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
    }

    public function testCallerOverrideWins(): void
    {
        $response = Response::raw('<b>x</b>');
        $response->header('Content-Security-Policy', "default-src 'none'");
        $headers = $this->sentHeaders($response);
        $this->assertSame("default-src 'none'", $headers['Content-Security-Policy']);
        // The other security headers are still applied.
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }

    public function testCallerOverrideWinsCaseInsensitively(): void
    {
        $response = Response::raw('<b>x</b>');
        $response->header('content-security-policy', "default-src 'none'");
        $headers = $this->sentHeaders($response);
        $this->assertSame("default-src 'none'", $headers['content-security-policy']);
        $this->assertArrayNotHasKey('Content-Security-Policy', $headers);
    }

    public function testConstructorRejectsOutOfRangeStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/between 100 and 599/');
        $this->expectExceptionMessageMatches('/Response::raw/');
        new Response([], 99);
    }

    public function testConstructorRejectsStatusAboveRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Response([], 600);
    }

    public function testConstructorAcceptsValidStatusCodes(): void
    {
        $this->assertSame(200, (new Response([]))->statusCode());
        $this->assertSame(404, (new Response([], 404))->statusCode());
        $this->assertSame(500, (new Response([], 500))->statusCode());
    }
}
