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
 * ThrottleMiddleware fallback strategy branches.
 */
final class ThrottleFallbackMutationTest extends TestCase
{
    private string $rateDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('REDIS_PORT=1');
        putenv('REDIS_HOST=127.0.0.1');
        $_ENV['REDIS_PORT'] = '1';
        $_ENV['REDIS_HOST'] = '127.0.0.1';
        $ref = new \ReflectionClass(\Siro\Core\Cache\CacheInstance::class);
        $prop = $ref->getProperty('sharedRedis');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        $this->rateDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';
        $this->cleanRateFiles();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('REDIS_PORT');
        putenv('REDIS_HOST');
        putenv('THROTTLE_FALLBACK');
        Env::reset();
        Cache::reset();
        $this->cleanRateFiles();
        parent::tearDown();
    }

    private function cleanRateFiles(): void
    {
        if (is_dir($this->rateDir)) {
            foreach (glob($this->rateDir . DIRECTORY_SEPARATOR . '*.json') as $f) {
                @unlink($f);
            }
        }
    }

    private function makeRequest(string $ip = '127.0.0.1', string $path = '/api/fb', string $method = 'GET'): Request
    {
        return new Request($method, $path, [], ['X-Forwarded-For' => $ip], [], $ip);
    }

    public function testFallbackDisabled(): void
    {
        putenv('THROTTLE_FALLBACK=disabled');
        $_ENV['THROTTLE_FALLBACK'] = 'disabled';
        $mw = new ThrottleMiddleware();
        $called = false;
        $resp = $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return Response::success();
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }

    public function testFallbackFailClosed(): void
    {
        putenv('THROTTLE_FALLBACK=fail_closed');
        $_ENV['THROTTLE_FALLBACK'] = 'fail_closed';
        $mw = new ThrottleMiddleware();
        $resp = $mw->handle($this->makeRequest(), fn () => Response::success());
        $this->assertSame(429, $resp->statusCode());
    }

    public function testFallbackFailOpen(): void
    {
        putenv('THROTTLE_FALLBACK=fail_open');
        $_ENV['THROTTLE_FALLBACK'] = 'fail_open';
        $mw = new ThrottleMiddleware();
        $called = false;
        $resp = $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return Response::success();
        });
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
    }

    public function testFallbackFileLimitExceeded(): void
    {
        putenv('THROTTLE_FALLBACK=file');
        $_ENV['THROTTLE_FALLBACK'] = 'file';
        $mw = new ThrottleMiddleware();
        for ($i = 0; $i < 2; $i++) {
            $mw->handle($this->makeRequest('10.99.0.9', '/api/fb'), fn () => Response::success(), 1, 1);
        }
        $resp = $mw->handle($this->makeRequest('10.99.0.9', '/api/fb'), fn () => Response::success(), 1, 1);
        $this->assertSame(429, $resp->statusCode());
        $headers = $resp->headers();
        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
    }

    public function testFallbackFileExpiredReset(): void
    {
        putenv('THROTTLE_FALLBACK=file');
        $_ENV['THROTTLE_FALLBACK'] = 'file';
        $file = $this->rateDir . DIRECTORY_SEPARATOR . 'rate.json';
        if (!is_dir($this->rateDir)) {
            mkdir($this->rateDir, 0775, true);
        }
        file_put_contents($file, json_encode(['rate:127.0.0.1:%2Fapi%2Ffb' => ['count' => 10, 'reset_at' => time() - 100]]));
        $mw = new ThrottleMiddleware();
        $called = false;
        $resp = $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return Response::success();
        }, 5, 1);
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
        @unlink($file);
    }

    public function testFallbackFileCorrupt(): void
    {
        putenv('THROTTLE_FALLBACK=file');
        $_ENV['THROTTLE_FALLBACK'] = 'file';
        $file = $this->rateDir . DIRECTORY_SEPARATOR . 'rate.json';
        if (!is_dir($this->rateDir)) {
            mkdir($this->rateDir, 0775, true);
        }
        file_put_contents($file, 'not-json{{{');
        $mw = new ThrottleMiddleware();
        $called = false;
        $resp = $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return Response::success();
        }, 5, 1);
        $this->assertTrue($called);
        $this->assertSame(200, $resp->statusCode());
        @unlink($file);
    }
}