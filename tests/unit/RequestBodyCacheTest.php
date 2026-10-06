<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Request;

/**
 * Request raw-body lifecycle: single accessor, per-request reset.
 *
 * Covers the long-running worker leak where a static body cache survived
 * across requests (FrankenPHP, FPM workers).
 */
final class RequestBodyCacheTest extends TestCase
{
    private array $origServer;
    private array $origGet;
    private array $origPost;

    protected function setUp(): void
    {
        $this->origServer = $_SERVER;
        $this->origGet = $_GET;
        $this->origPost = $_POST;
        Request::resetCache();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->origServer;
        $_GET = $this->origGet;
        $_POST = $this->origPost;
        Request::resetCache();
    }

    private function seedStaleCache(string $body): void
    {
        $ref = new \ReflectionProperty(Request::class, 'rawBodyCache');
        $ref->setAccessible(true);
        $ref->setValue(null, $body);
    }

    public function testResetCacheClearsStaleBody(): void
    {
        $this->seedStaleCache('{"email":"stale@example.com"}');
        $this->assertSame('{"email":"stale@example.com"}', Request::rawBody());

        Request::resetCache();

        $this->assertNull(Request::getRawBodyCache());
    }

    public function testGetRequestAfterResetHasNoStaleBody(): void
    {
        // Simulate a previous POST request that populated the static cache.
        $this->seedStaleCache('{"email":"stale@example.com"}');

        // Long-running runtimes must reset between requests (App::run does this).
        Request::resetCache();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/users';
        $_GET = [];
        $_POST = [];

        $request = Request::fromGlobals();

        $this->assertSame([], $request->body());
    }

    public function testRawBodyServesCacheWithoutRereadingStream(): void
    {
        $this->seedStaleCache('{"cached":true}');

        // php://input is empty under CLI; a cache hit must not touch the stream.
        $this->assertSame('{"cached":true}', Request::rawBody());
        $this->assertSame('{"cached":true}', Request::rawBody());
    }
}
