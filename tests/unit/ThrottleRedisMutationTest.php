<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Env;
use Siro\Core\Middleware\ThrottleMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * ThrottleMiddleware Redis path (real Redis on server).
 */
final class ThrottleRedisMutationTest extends TestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('REDIS_PREFIX=' . ($this->prefix = 'siro:tth:' . uniqid() . ':'));
        $_ENV['REDIS_PREFIX'] = $this->prefix;
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('REDIS_PREFIX');
        Env::reset();
        Cache::reset();
        parent::tearDown();
    }

    private function redisAvailable(): bool
    {
        try {
            $r = new \Redis();
            return $r->connect('127.0.0.1', 6379, 1);
        } catch (\Throwable) {
            return false;
        }
    }

    private function makeRequest(string $ip = '127.0.0.1', string $path = '/api/tred', string $method = 'GET'): Request
    {
        return new Request($method, $path, [], ['X-Forwarded-For' => $ip], [], $ip);
    }

    public function testRedisUnderLimitPasses(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw = new ThrottleMiddleware();
        $called = false;
        $resp = $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return Response::success();
        }, 100, 1);
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
        $headers = $resp->headers();
        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
    }

    public function testRedisBlockedReturns429(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw = new ThrottleMiddleware();
        for ($i = 0; $i < 3; $i++) {
            $mw->handle($this->makeRequest('10.20.30.40', '/api/blocked'), fn () => Response::success(), 2, 1);
        }
        $resp = $mw->handle($this->makeRequest('10.20.30.40', '/api/blocked'), fn () => Response::success(), 2, 1);
        $this->assertSame(429, $resp->statusCode());
        $headers = $resp->headers();
        $this->assertArrayHasKey('Retry-After', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
    }

    public function testRedisFirstRequestHasRemainingMinusOne(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw = new ThrottleMiddleware();
        $resp = $mw->handle($this->makeRequest('10.20.30.50', '/api/remaining'), fn () => Response::success(), 10, 1);
        $headers = $resp->headers();
        $this->assertSame('10', $headers['X-RateLimit-Limit']);
        $this->assertSame('9', $headers['X-RateLimit-Remaining']);
    }

    public function testRedisSecondRequestDecrementsRemaining(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw = new ThrottleMiddleware();
        $mw->handle($this->makeRequest('10.20.30.60', '/api/decr'), fn () => Response::success(), 5, 1);
        $resp = $mw->handle($this->makeRequest('10.20.30.60', '/api/decr'), fn () => Response::success(), 5, 1);
        $headers = $resp->headers();
        $this->assertSame('5', $headers['X-RateLimit-Limit']);
        $this->assertSame('3', $headers['X-RateLimit-Remaining']);
    }

    public function testRedisCustomPrefixIsolation(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw1 = new ThrottleMiddleware();
        $mw2 = new ThrottleMiddleware();
        $resp1 = $mw1->handle($this->makeRequest('10.20.30.70', '/api/prefix1'), fn () => Response::success(), 100, 1);
        $resp2 = $mw2->handle($this->makeRequest('10.20.30.70', '/api/prefix1'), fn () => Response::success(), 100, 1);
        $this->assertSame(200, $resp1->statusCode());
        $this->assertSame(200, $resp2->statusCode());
    }

    public function testRedisNumericIdPathNormalized(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw = new ThrottleMiddleware();
        $resp = $mw->handle($this->makeRequest('10.20.30.80', '/api/users/12345'), fn () => Response::success(), 100, 1);
        $this->assertSame(200, $resp->statusCode());
        $resp2 = $mw->handle($this->makeRequest('10.20.30.80', '/api/users/99999'), fn () => Response::success(), 100, 1);
        $this->assertSame(200, $resp2->statusCode());
    }

    public function testRedisZeroCountThrowsRuntimeException(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw = new ThrottleMiddleware();
        $resp = $mw->handle($this->makeRequest('10.20.30.90', '/api/zero'), fn () => Response::success(), 50, 1);
        $this->assertSame(200, $resp->statusCode());
    }

    public function testRedisHeadersIncludeResetOnSuccess(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw = new ThrottleMiddleware();
        $resp = $mw->handle($this->makeRequest('10.20.30.95', '/api/reset-header'), fn () => Response::success(), 100, 2);
        $headers = $resp->headers();
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
        $this->assertGreaterThan(time(), (int) $headers['X-RateLimit-Reset']);
    }

    public function testRedisZeroTtlOneMinuteMinimum(): void
    {
        if (!$this->redisAvailable()) {
            $this->markTestSkipped('Redis not available');
        }
        $mw = new ThrottleMiddleware();
        $resp = $mw->handle($this->makeRequest('10.20.30.96', '/api/min-ttl'), fn () => Response::success(), 10, 0);
        $this->assertSame(200, $resp->statusCode());
    }
}