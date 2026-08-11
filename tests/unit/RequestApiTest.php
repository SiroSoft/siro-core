<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Request;

/**
 * Request API tests — constructor, method/path/query/body/headers/input,
 * params, user, ip, file access, version, validation.
 */
final class RequestApiTest extends TestCase
{
    private function makeRequest(array $extra = []): Request
    {
        $base = [
            'GET',
            '/api/users/5?page=2&active=1',
            ['page' => '2', 'active' => '1'],
            ['Content-Type' => 'application/json', 'X-Custom' => 'abc', 'User-Agent' => 'test-agent'],
            ['email' => 'a@test.com', 'password' => 'secret'],
            '192.168.1.10',
        ];
        // Merge by named keys so overrides actually replace (array_merge re-indexes numerics)
        $args = array_replace($base, $extra);
        return new Request(...array_values($args));
    }

    public function testMethodAndPath(): void
    {
        $r = $this->makeRequest();
        $this->assertSame('GET', $r->method());
        // constructor keeps path as-is (query string included)
        $this->assertSame('/api/users/5?page=2&active=1', $r->path());
    }

    public function testQuerySingleAndAll(): void
    {
        $r = $this->makeRequest();
        $this->assertSame('2', $r->query('page'));
        $this->assertSame('1', $r->query('active'));
        $this->assertNull($r->query('missing'));
        $this->assertSame('fallback', $r->query('missing', 'fallback'));
        $this->assertArrayHasKey('page', $r->queryAll());
    }

    public function testHeaders(): void
    {
        $r = $this->makeRequest();
        $this->assertSame('application/json', $r->header('Content-Type'));
        $this->assertSame('abc', $r->header('X-Custom'));
        $this->assertSame('test-agent', $r->header('User-Agent'));
        $this->assertNull($r->header('Missing'));
        // headers are normalized to lowercase keys
        $this->assertArrayHasKey('content-type', $r->headersAll());
        $this->assertSame('application/json', $r->headers()['content-type']);
    }

    public function testBodyAndInput(): void
    {
        $r = $this->makeRequest();
        $this->assertSame(['email' => 'a@test.com', 'password' => 'secret'], $r->body());
        $this->assertSame('a@test.com', $r->input('email'));
        $this->assertSame('secret', $r->input('password'));
        $this->assertNull($r->input('missing'));
        $this->assertSame(['email', 'password'], array_keys($r->inputAll()));
    }

    public function testJsonAll(): void
    {
        $r = $this->makeRequest();
        $this->assertSame(['email' => 'a@test.com', 'password' => 'secret'], $r->jsonAll());
    }

    public function testParamAndSetParams(): void
    {
        $r = $this->makeRequest();
        // params from path args not in base; setParams provides them
        $r->setParams(['id' => '5']);
        $this->assertSame('5', $r->param('id'));
        $this->assertNull($r->param('missing'));
    }

    public function testUserAttributeVersion(): void
    {
        $r = $this->makeRequest();
        $r->setUser(['id' => 1, 'role' => 'admin']);
        $this->assertSame(['id' => 1, 'role' => 'admin'], $r->user());

        $r->setAttribute('custom', 'val');
        $this->assertSame('val', $r->getAttribute('custom'));

        $r->setVersion(2);
        $this->assertSame(2, $r->version());
        $handler = fn () => 'ok';
        $r->setVersionedHandler($handler);
        $this->assertSame($handler, $r->versionedHandler());
    }

    public function testIp(): void
    {
        $r = $this->makeRequest();
        $this->assertSame('192.168.1.10', $r->ip());
    }

    public function testFileAccess(): void
    {
        $r = $this->makeRequest();
        $this->assertFalse($r->hasFile('avatar'));
        $this->assertNull($r->file('avatar'));
        $this->assertSame([], $r->allFiles());
    }

    public function testValidatePasses(): void
    {
        $r = $this->makeRequest();
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|min:3']);
        $this->assertSame('a@test.com', $data['email']);
    }

    public function testValidateFailsThrows(): void
    {
        $r = $this->makeRequest(['GET', '/api/x', [], [], ['email' => 'not-an-email']]);
        $this->expectException(\Siro\Core\ValidationException::class);
        $r->validate(['email' => 'required|email']);
    }

    public function testValidated(): void
    {
        $r = $this->makeRequest();
        $data = $r->validated(['email' => 'required']);
        $this->assertArrayHasKey('email', $data);
    }

    public function testCacheKey(): void
    {
        $r = $this->makeRequest();
        $key = $r->cacheKey();
        $this->assertNotEmpty($key);
    }
}
