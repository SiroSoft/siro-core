<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Siro\Core\Webhook\WebhookSigner;

/**
 * @covers \Siro\Core\Webhook\WebhookSigner
 */
final class WebhookSignerTest extends TestCase
{
    private const string SECRET = 'whsec_test_secret_123';

    private const string PAYLOAD = '{"order_id": 7, "total": 99.5}';

    public function testSignProducesCanonicalHeaderFormat(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        $this->assertMatchesRegularExpression('/^t=1700000000,v1=[0-9a-f]{64}$/', $header);
    }

    public function testSignUsesCurrentTimeWhenTimestampOmitted(): void
    {
        $before = time();
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET);
        $after = time();
        preg_match('/^t=(\d+),v1=/', $header, $m);
        $this->assertGreaterThanOrEqual($before, (int) $m[1]);
        $this->assertLessThanOrEqual($after, (int) $m[1]);
    }

    public function testVerifyRoundTrip(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        $this->assertTrue(WebhookSigner::verify(self::PAYLOAD, $header, self::SECRET, 300, 1700000000));
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        $this->assertFalse(WebhookSigner::verify(self::PAYLOAD, $header, 'wrong-secret', 300, 1700000000));
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        $this->assertFalse(WebhookSigner::verify('{"order_id": 8, "total": 99.5}', $header, self::SECRET, 300, 1700000000));
    }

    public function testVerifyRejectsTamperedSignature(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        $tampered = preg_replace('/v1=[0-9a-f]/', 'v1=0', $header, 1);
        $this->assertIsString($tampered);
        $this->assertFalse(WebhookSigner::verify(self::PAYLOAD, $tampered, self::SECRET, 300, 1700000000));
    }

    public function testVerifyRejectsStaleTimestamp(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        // 301 seconds later — just outside the 300s replay window.
        $this->assertFalse(WebhookSigner::verify(self::PAYLOAD, $header, self::SECRET, 300, 1700000301));
    }

    public function testVerifyAcceptsTimestampAtToleranceBoundary(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        // Exactly 300 seconds later — inside the window (inclusive).
        $this->assertTrue(WebhookSigner::verify(self::PAYLOAD, $header, self::SECRET, 300, 1700000300));
        // A timestamp 300 seconds in the future is also inside the window.
        $this->assertTrue(WebhookSigner::verify(self::PAYLOAD, $header, self::SECRET, 300, 1699999700));
    }

    public function testVerifyRejectsFutureTimestampBeyondTolerance(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        $this->assertFalse(WebhookSigner::verify(self::PAYLOAD, $header, self::SECRET, 300, 1699999699));
    }

    public function testVerifyRejectsMalformedHeaders(): void
    {
        $payload = self::PAYLOAD;
        $secret = self::SECRET;
        $now = 1700000000;
        $this->assertFalse(WebhookSigner::verify($payload, '', $secret, 300, $now));
        $this->assertFalse(WebhookSigner::verify($payload, 'garbage', $secret, 300, $now));
        $this->assertFalse(WebhookSigner::verify($payload, 't=1700000000', $secret, 300, $now));
        $this->assertFalse(WebhookSigner::verify($payload, 'v1=' . hash_hmac('sha256', 'x', $secret), $secret, 300, $now));
        $this->assertFalse(WebhookSigner::verify($payload, 't=not-a-number,v1=abc', $secret, 300, $now));
        $this->assertFalse(WebhookSigner::verify($payload, 't=1700000000,v1=', $secret, 300, $now));
        $this->assertFalse(WebhookSigner::verify($payload, 't=1700000000,v1=ZZZ', $secret, 300, $now));
    }

    public function testVerifyAcceptsFieldsInAnyOrder(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        [$tPart, $vPart] = explode(',', $header);
        $reordered = $vPart . ',' . $tPart;
        $this->assertTrue(WebhookSigner::verify(self::PAYLOAD, $reordered, self::SECRET, 300, 1700000000));
    }

    public function testVerifyIgnoresUnknownFields(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000) . ',v0=legacy';
        $this->assertTrue(WebhookSigner::verify(self::PAYLOAD, $header, self::SECRET, 300, 1700000000));
    }

    public function testSignRejectsEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebhookSigner::sign(self::PAYLOAD, '');
    }

    public function testVerifyWithEmptySecretFailsClosed(): void
    {
        $header = WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000);
        $this->assertFalse(WebhookSigner::verify(self::PAYLOAD, $header, '', 300, 1700000000));
    }

    public function testDifferentPayloadsProduceDifferentSignatures(): void
    {
        $a = WebhookSigner::sign('{"a": 1}', self::SECRET, 1700000000);
        $b = WebhookSigner::sign('{"a": 2}', self::SECRET, 1700000000);
        $this->assertNotSame($a, $b);
    }

    public function testSignatureHeadersReturnsCanonicalHeader(): void
    {
        $headers = WebhookSigner::signatureHeaders(self::PAYLOAD, self::SECRET, 1700000000);
        $this->assertSame(['X-Siro-Signature' => WebhookSigner::sign(self::PAYLOAD, self::SECRET, 1700000000)], $headers);
        $this->assertSame(WebhookSigner::HEADER, 'X-Siro-Signature');
    }
}
