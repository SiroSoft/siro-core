<?php

declare(strict_types=1);

namespace Siro\Core\Testing;

use PHPUnit\Framework\Assert;
use Siro\Core\Response;

/**
 * Fluent assertions over a dispatched Response.
 *
 * Returned by TestClient HTTP helpers; every assert returns $this for
 * chaining. Payload shape follows the Siro envelope:
 * {success, message, data, meta}.
 *
 * @package Siro\Core\Testing
 */
final class TestResponse
{
    public function __construct(
        private readonly Response $response
    ) {
    }

    public function statusCode(): int
    {
        return $this->response->statusCode();
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->response->payload();
        return $payload;
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->response->statusCode());
        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    public function assertSuccessful(): self
    {
        Assert::assertTrue($this->json()['success'] ?? false, 'Expected success envelope.');
        return $this;
    }

    /**
     * Assert a dot-notation path inside the payload, e.g. 'data.id'.
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        $actual = $this->valueAt($path);
        Assert::assertSame($expected, $actual, "JSON path [{$path}] mismatch.");
        return $this;
    }

    public function assertJsonPathExists(string $path): self
    {
        Assert::assertTrue($this->hasPath($path), "JSON path [{$path}] is missing.");
        return $this;
    }

    /**
     * Assert all given key/value pairs exist somewhere in data/meta.
     *
     * @param array<string, mixed> $fragment
     */
    public function assertJsonFragment(array $fragment): self
    {
        foreach ($fragment as $key => $value) {
            $this->assertJsonPath('data.' . $key, $value);
        }
        return $this;
    }

    public function assertJsonCount(int $expected, string $path = 'data'): self
    {
        $value = $this->valueAt($path);
        Assert::assertIsArray($value, "JSON path [{$path}] is not countable.");
        Assert::assertCount($expected, $value);
        return $this;
    }

    private function valueAt(string $path): mixed
    {
        $current = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    private function hasPath(string $path): bool
    {
        $current = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }
        return true;
    }
}
