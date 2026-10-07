<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\MalformedBodyException;
use Siro\Core\Request;
use Siro\Core\Tests\TestCase;

/**
 * Malformed request bodies report malformed_body (400), not a misleading
 * "field is required" validation error (Issue 6).
 */
final class MalformedBodyTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::resetCache();
        parent::tearDown();
    }

    public function testValidateThrowsMalformedBodyException(): void
    {
        $request = new Request('POST', '/api/users', [], ['content-type' => 'application/json'], []);
        $request->setBodyParseFailed();

        try {
            $request->validate(['email' => 'required|email']);
            $this->fail('Expected MalformedBodyException');
        } catch (MalformedBodyException $e) {
            $this->assertSame(400, $e->getCode());
        }
    }

    public function testMalformedBodyResponseShape(): void
    {
        $response = (new MalformedBodyException())->toResponse();

        $this->assertSame(400, $response->statusCode());
        $payload = $response->payload();
        $this->assertFalse($payload['success']);
        $this->assertSame('malformed_body', $payload['meta']['error_code'] ?? null);
        $this->assertArrayHasKey('body', $payload['meta']['errors'] ?? []);
    }

    public function testValidBodyValidatesNormally(): void
    {
        $request = new Request(
            'POST',
            '/api/users',
            [],
            ['content-type' => 'application/json'],
            ['email' => 'user@example.com']
        );

        $this->assertFalse($request->bodyParseFailed());
        $validated = $request->validate(['email' => 'required|email']);
        $this->assertSame(['email' => 'user@example.com'], $validated);
    }
}
