<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Env;
use Siro\Core\Metrics;
use Siro\Core\Middleware\MetricsMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * MetricsMiddleware normalizePath branches + handle flow.
 */
final class MetricsMiddlewareMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        parent::tearDown();
    }

    private function normalize(string $path): string
    {
        $mw = new MetricsMiddleware();
        $ref = new \ReflectionMethod($mw, 'normalizePath');
        return $ref->invoke($mw, $path);
    }

    public function testNormalizeUuid(): void
    {
        $this->assertSame('/api/{uuid}', $this->normalize('/api/123e4567-e89b-12d3-a456-426614174000'));
    }

    public function testNormalizeDigit(): void
    {
        $this->assertSame('/api/users/{id}', $this->normalize('/api/users/42'));
    }

    public function testNormalizeHash32(): void
    {
        $this->assertSame('/files/{hash}', $this->normalize('/files/ab12cd34ef56ab78cd90ef12ab34cd56'));
    }

    public function testNormalizeHash64(): void
    {
        $this->assertSame('/hash/{hash}', $this->normalize('/hash/ab12cd34ef56ab78cd90ef12ab34cd56ab12cd34ef56ab78cd90ef12ab34cd56'));
    }

    public function testNormalizePlain(): void
    {
        $this->assertSame('/api/users', $this->normalize('/api/users'));
    }

    public function testNormalizeMixed(): void
    {
        $this->assertSame('/a/{id}/b/{uuid}', $this->normalize('/a/123/b/123e4567-e89b-12d3-a456-426614174000'));
    }

    public function testHandleRecordsMetrics(): void
    {
        $mw = new MetricsMiddleware();
        $req = new Request('GET', '/api/users/42');
        $called = false;
        $resp = $mw->handle($req, function () use (&$called) {
            $called = true;
            return Response::success(['x' => 1]);
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }

    public function testHandleNonResponseNext(): void
    {
        $mw = new MetricsMiddleware();
        $req = new Request('GET', '/api/plain');
        $result = $mw->handle($req, fn () => ['raw' => true]);
        $this->assertSame(['raw' => true], $result);
    }
}