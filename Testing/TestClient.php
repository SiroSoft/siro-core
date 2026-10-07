<?php

declare(strict_types=1);

namespace Siro\Core\Testing;

use Siro\Core\AuthorizationException;
use Siro\Core\MalformedBodyException;
use Siro\Core\ModelNotFoundException;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;

/**
 * Server-less HTTP test client: dispatches Requests through a Router and
 * returns fluent TestResponse assertions. No web server, no sockets.
 *
 * Usage:
 *   $client = TestClient::through($router)->actingAs(['id' => 1, 'role' => 'admin']);
 *   $client->postJson('/api/products', ['name' => 'Siro'])
 *       ->assertCreated()
 *       ->assertJsonPath('data.name', 'Siro');
 *
 * @package Siro\Core\Testing
 */
final class TestClient
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private function __construct(
        private readonly Router $router
    ) {
    }

    public static function through(Router $router): self
    {
        return new self($router);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers[strtolower((string) $name)] = (string) $value;
        }
        return $clone;
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return $this->withHeaders(['Authorization' => $type . ' ' . $token]);
    }

    /**
     * @param array<string, mixed> $user Authenticated user attached to requests.
     */
    public function actingAs(array $user): self
    {
        $clone = clone $this;
        $clone->user = $user;
        return $clone;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function get(string $path, array $query = []): TestResponse
    {
        return $this->call('GET', $path, [], $query);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    public function postJson(string $path, array $body = [], array $query = []): TestResponse
    {
        return $this->call('POST', $path, $body, $query, ['content-type' => 'application/json']);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    public function putJson(string $path, array $body = [], array $query = []): TestResponse
    {
        return $this->call('PUT', $path, $body, $query, ['content-type' => 'application/json']);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    public function patchJson(string $path, array $body = [], array $query = []): TestResponse
    {
        return $this->call('PATCH', $path, $body, $query, ['content-type' => 'application/json']);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function delete(string $path, array $query = []): TestResponse
    {
        return $this->call('DELETE', $path, [], $query);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, string> $extraHeaders
     */
    public function call(string $method, string $path, array $body = [], array $query = [], array $extraHeaders = []): TestResponse
    {
        $headers = $this->headers;
        foreach ($extraHeaders as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
        if ($this->user !== null) {
            $request->setUser($this->user);
        }
        try {
            return new TestResponse($this->router->dispatch($request));
        } catch (ValidationException|MalformedBodyException|ModelNotFoundException|AuthorizationException $e) {
            // Mirror App::run() so HTTP-level assertions see status codes,
            // not bubbled exceptions.
            return new TestResponse($e->toResponse());
        }
    }
}
