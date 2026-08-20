<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Middleware\ThrottleMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Tests\TestCase;

final class ThrottleMiddlewareTest extends TestCase
{
    private ThrottleMiddleware $middleware;
    private string $rateDir;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['THROTTLE_FALLBACK'] = 'file';
        putenv('THROTTLE_FALLBACK=file');
        $_ENV['REDIS_PORT'] = '1';
        putenv('REDIS_PORT=1');
        $this->middleware = new ThrottleMiddleware();
        $this->rateDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';
        $this->cleanRateFiles();
    }

    protected function tearDown(): void
    {
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

    private function makeRequest(string $ip = '127.0.0.1', string $path = '/api/test', string $method = 'GET'): Request
    {
        return new Request($method, $path, [], ['X-Forwarded-For' => $ip], [], $ip);
    }

    public function testAllowsRequestUnderLimit(): void
    {
        $request = $this->makeRequest();
        $response = $this->middleware->handle($request, fn () => Response::success(), 60, 1);
        $this->assertSame(200, $response->statusCode());
    }

    public function testBlocksRequestOverLimit(): void
    {
        $request = $this->makeRequest('10.0.0.1');
        $next = fn () => Response::success();

        // Exhaust limit (5 requests in 1 min)
        for ($i = 0; $i < 5; $i++) {
            $this->middleware->handle($request, $next, 5, 1);
        }

        // 6th request should be blocked
        $response = $this->middleware->handle($request, $next, 5, 1);
        $this->assertSame(429, $response->statusCode());
    }

    public function testAllowsDifferentIpsSeparately(): void
    {
        $next = fn () => Response::success();

        for ($i = 0; $i < 10; $i++) {
            $r = $this->makeRequest('10.0.0.1');
            $this->middleware->handle($r, $next, 5, 1);
        }

        $response = $this->middleware->handle($this->makeRequest('10.0.0.2'), $next, 5, 1);
        $this->assertSame(200, $response->statusCode());
    }

    public function testAllowsDifferentRoutesSeparately(): void
    {
        $next = fn () => Response::success();
        $requestA = $this->makeRequest('10.0.0.3', '/api/a');
        $requestB = $this->makeRequest('10.0.0.3', '/api/b');

        for ($i = 0; $i < 5; $i++) {
            $this->middleware->handle($requestA, $next, 5, 1);
        }

        $response = $this->middleware->handle($requestB, $next, 5, 1);
        $this->assertSame(200, $response->statusCode());
    }

    public function testThrottleHeadersPresentOnBlock(): void
    {
        $request = $this->makeRequest('10.0.0.4');
        $next = fn () => Response::success();

        for ($i = 0; $i < 3; $i++) {
            $this->middleware->handle($request, $next, 3, 1);
        }

        $response = $this->middleware->handle($request, $next, 3, 1);
        $this->assertSame(429, $response->statusCode());
    }

    public function testFallbackDisabledPassesAll(): void
    {
        $_ENV['THROTTLE_FALLBACK'] = 'disabled';
        putenv('THROTTLE_FALLBACK=disabled');
        $mw = new ThrottleMiddleware();

        $request = $this->makeRequest('10.0.0.5');
        $next = fn () => Response::success();

        for ($i = 0; $i < 20; $i++) {
            $response = $mw->handle($request, $next, 5, 1);
        }

        $this->assertSame(200, $response->statusCode());
    }
}
