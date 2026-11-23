<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Response;
use Siro\Core\Tests\TestCase;

/**
 * P0-1: Response::send() must hex-escape HTML-significant characters in
 * JSON output (JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
 * so a payload such as `<script>` never leaves the API as raw HTML.
 * Regression: SecurityTest::testXssInProductNameIsEscaped (SiroERP).
 */
final class ResponseJsonEncodingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // send() appends a `debug` key while static debug mode is on.
        // An earlier test may boot App with debug=true and the shared
        // TestCase does not reset it, so force it off here — this file
        // asserts the exact JSON body, which must not depend on that
        // global state.
        Response::enableDebug(false);
    }

    private function sendBody(Response $response): string
    {
        ob_start();
        $response->send();
        $out = ob_get_clean();
        return is_string($out) ? $out : '';
    }

    public function testScriptTagIsHexEscaped(): void
    {
        $out = $this->sendBody(Response::json(['name' => "<script>alert('xss')</script>"]));
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('\\u003Cscript\\u003E', $out);
    }

    public function testAmpAposQuotAreHexEscaped(): void
    {
        $out = $this->sendBody(Response::json(['v' => 'a & b \'c\' "d"']));
        $this->assertStringNotContainsString("a & b 'c' \"d\"", $out);
        $this->assertStringContainsString('\\u0026', $out);
        $this->assertStringContainsString('\\u0027', $out);
        $this->assertStringContainsString('\\u0022', $out);
    }

    public function testVietnameseStaysUnescaped(): void
    {
        $out = $this->sendBody(Response::json(['name' => 'Sản phẩm Việt Nam']));
        $this->assertStringContainsString('Sản phẩm Việt Nam', $out);
    }

    public function testJsonRoundTripDecodesToOriginal(): void
    {
        $payload = ['name' => "<script>alert('xss')</script>", 'note' => 'a & b'];
        $out = $this->sendBody(Response::json($payload));
        $decoded = json_decode($out, true);
        $this->assertSame($payload, $decoded);
    }
}
