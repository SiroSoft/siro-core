<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Request;

/**
 * Extra Request branches: typed accessors, files, version, attributes.
 */
final class RequestTypedMutationTest extends TestCase
{
    private function makeRequest(array $query = [], array $headers = [], array $body = []): Request
    {
        return new Request('POST', '/api/x', $query, $headers, $body);
    }

    public function testTypedAccessors(): void
    {
        $r = $this->makeRequest([], [], ['age' => '30', 'name' => 'abc', 'on' => '1', 'tags' => ['a', 'b'], 'pi' => '3.14']);
        $this->assertSame(30, $r->int('age'));
        $this->assertSame(0, $r->int('missing'));
        $this->assertSame('abc', $r->string('name'));
        $this->assertSame('', $r->string('missing'));
        $this->assertTrue($r->bool('on'));
        $this->assertFalse($r->bool('missing'));
        $this->assertSame(['a', 'b'], $r->array('tags'));
        $this->assertSame([], $r->array('missing'));
        $this->assertSame(3.14, $r->float('pi'));
        $this->assertSame(0.0, $r->float('missing'));
    }

    public function testQueryTyped(): void
    {
        $r = $this->makeRequest(['page' => '5', 'q' => 'search']);
        $this->assertSame(5, $r->queryInt('page'));
        $this->assertSame(0, $r->queryInt('missing'));
        $this->assertSame('search', $r->queryString('q'));
        $this->assertSame('', $r->queryString('missing'));
    }

    public function testAllAndOnlyExcept(): void
    {
        $r = $this->makeRequest([], [], ['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $r->all());
        $this->assertSame(['a' => 1], $r->only(['a']));
        $this->assertSame(['b' => 2, 'c' => 3], $r->except(['a']));
    }

    public function testVersionAndVersionedHandler(): void
    {
        $r = $this->makeRequest();
        $r->setVersion(3);
        $this->assertSame(3, $r->version());
        $r->setVersionedHandler('X@y');
        $this->assertSame('X@y', $r->versionedHandler());
    }

    public function testUserAndAttributes(): void
    {
        $r = $this->makeRequest();
        $r->setUser(['id' => 9]);
        $this->assertSame(['id' => 9], $r->user());
        $r->setAttribute('custom', 'val');
        $this->assertSame('val', $r->getAttribute('custom'));
        $this->assertNull($r->getAttribute('missing'));
    }

    public function testFromGlobals(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/from-globals';
        $r = Request::fromGlobals();
        $this->assertSame('GET', $r->method());
        $this->assertSame('/from-globals', $r->path());
    }

    public function testHasFileAndFile(): void
    {
        $r = $this->makeRequest();
        $this->assertFalse($r->hasFile('none'));
        $this->assertNull($r->file('none'));
        $this->assertSame([], $r->allFiles());
    }

    public function testValidateFileMissing(): void
    {
        $r = $this->makeRequest();
        $this->assertNull($r->validateFile('none', ['image']));
    }

    public function testCacheKey(): void
    {
        $r = $this->makeRequest(['page' => '1'], ['X-Header' => 'v']);
        $this->assertIsString($r->cacheKey());
        $this->assertNotEmpty($r->cacheKey());
    }

    public function testJsonAllAndBody(): void
    {
        $r = $this->makeRequest([], [], ['x' => 1]);
        $this->assertSame(['x' => 1], $r->body());
        $this->assertSame(['x' => 1], $r->jsonAll());
    }

    public function testInputAllAndParam(): void
    {
        $r = $this->makeRequest([], [], ['a' => 1]);
        $r->setParams(['id' => 42]);
        $this->assertSame(['a' => 1], $r->inputAll());
        $this->assertSame('42', $r->param('id'));
        $this->assertSame(1, $r->input('a'));
    }
}
