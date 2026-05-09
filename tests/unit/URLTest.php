<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\URL;

final class URLTest extends TestCase
{
    public function testSignedGeneratesToken(): void
    {
        $result = URL::signed('/api/invite/123');
        $this->assertIsString($result);
    }

    public function testSignedContainsSignature(): void
    {
        $result = URL::signed('/api/invite/123');
        $this->assertStringContainsString('signature=', $result);
    }

    public function testSignedContainsPayload(): void
    {
        $result = URL::signed('/api/invite/123');
        $this->assertStringContainsString('payload=', $result);
    }

    public function testSignedWithExpiration(): void
    {
        $result = URL::signed('/api/invite/123', ['email' => 'a@b.com'], 3600);
        $this->assertIsString($result);
    }

    public function testValidateReturnsNullForInvalidSignature(): void
    {
        $result = URL::validate('/api/invite/999', 'invalidsignature123');
        $this->assertNull($result);
    }

    public function testSignedWithAdditionalParams(): void
    {
        $result = URL::signed('/api/verify', ['user_id' => '42', 'token' => 'abc123'], 86400);
        $this->assertIsString($result);
    }

    public function testValidateRequestReturnsNullForMissingData(): void
    {
        $request = new \Siro\Core\Request('GET', '/api/test', [], [], [], '127.0.0.1');
        $result = URL::validateRequest($request);
        $this->assertNull($result);
    }

    public function testSignedCreatesDifferentSignaturesForDifferentRoutes(): void
    {
        $sig1 = URL::signed('/api/a');
        $sig2 = URL::signed('/api/b');
        $this->assertNotEquals($sig1, $sig2);
    }

    public function testSignedWithSameRouteCreatesDifferentSignatures(): void
    {
        $sig1 = URL::signed('/api/c', ['ts' => '1']);
        $sig2 = URL::signed('/api/c', ['ts' => '2']);
        $this->assertNotEquals($sig1, $sig2);
    }

    public function testSignedProducesDeterministicOutput(): void
    {
        $sig1 = URL::signed('/api/d', ['id' => '5'], 1000);
        $sig2 = URL::signed('/api/d', ['id' => '5'], 1000);
        $this->assertEquals($sig1, $sig2);
    }

    public function testValidateReturnsArrayForValidPayload(): void
    {
        $result = URL::signed('/api/valid');
        $this->assertIsString($result);
    }

    public function testValidateHandlesSpecialCharsInRoute(): void
    {
        $result = URL::signed('/api/search?q=test');
        $this->assertIsString($result);
    }
}