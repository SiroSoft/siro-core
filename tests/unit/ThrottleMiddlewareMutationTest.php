<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Middleware\ThrottleMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Tests\TestCase;

/**
 * Strong-assertion tests targeting uncovered branches of ThrottleMiddleware.
 */
final class ThrottleMiddlewareMutationTest extends TestCase
{
    private string $rateDir;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['THROTTLE_FALLBACK'] = 'file';
        putenv('THROTTLE_FALLBACK=file');
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

    public function testFailClosedReturns429(): void
    {
        $_ENV['THROTTLE_FALLBACK'] = 'fail_closed';
        putenv('THROTTLE_FALLBACK=fail_closed');
        $mw = new ThrottleMiddleware();

        $response = $mw->handle($this->makeRequest(), fn () => Response::success());
        $this->assertSame(429, $response->statusCode());
    }

    public function testFailOpenPassesThrough(): void
    {
        $_ENV['THROTTLE_FALLBACK'] = 'fail_open';
        putenv('THROTTLE_FALLBACK=fail_open');
        $mw = new ThrottleMiddleware();

        $response = $mw->handle($this->makeRequest(), fn () => Response::success());
        $this->assertSame(200, $response->statusCode());
    }

    public function testUnknownFallbackUsesFile(): void
    {
        $_ENV['THROTTLE_FALLBACK'] = 'some_random_strategy';
        putenv('THROTTLE_FALLBACK=some_random_strategy');
        $mw = new ThrottleMiddleware();

        $response = $mw->handle($this->makeRequest(), fn () => Response::success(), 3, 1);
        $this->assertSame(200, $response->statusCode());

        // now exhaust
        for ($i = 0; $i < 3; $i++) {
            $mw->handle($this->makeRequest(), fn () => Response::success(), 3, 1);
        }
        $blocked = $mw->handle($this->makeRequest(), fn () => Response::success(), 3, 1);
        $this->assertSame(429, $blocked->statusCode());
    }

    public function testBlockResponseHasRetryAfter(): void
    {
        $mw = new ThrottleMiddleware();
        $request = $this->makeRequest('10.99.0.1', '/api/limit');
        $next = fn () => Response::success();

        for ($i = 0; $i < 4; $i++) {
            $mw->handle($request, $next, 4, 1);
        }
        $response = $mw->handle($request, $next, 4, 1);
        $this->assertSame(429, $response->statusCode());
        $headers = $response->headers();
        $this->assertArrayHasKey('Retry-After', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
    }

    public function testSuccessResponseHasResetHeader(): void
    {
        $mw = new ThrottleMiddleware();
        $response = $mw->handle($this->makeRequest(), fn () => Response::success(), 60, 1);
        $this->assertSame(200, $response->statusCode());
        $headers = $response->headers();
        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
    }

    public function testMinMaxRequestsClamped(): void
    {
        $mw = new ThrottleMiddleware();
        $request = $this->makeRequest('10.99.0.2');
        $next = fn () => Response::success();

        // maxRequests=0 clamps to 1 -> second request blocked
        $mw->handle($request, $next, 0, 1);
        $blocked = $mw->handle($request, $next, 0, 1);
        $this->assertSame(429, $blocked->statusCode());
    }

    public function testMinMinutesClamped(): void
    {
        $mw = new ThrottleMiddleware();
        $request = $this->makeRequest('10.99.0.3');
        $next = fn () => Response::success();

        // minutes=0 clamps to 1
        $response = $mw->handle($request, $next, 60, 0);
        $this->assertSame(200, $response->statusCode());
    }

    public function testNormalizePathWithNumericSegments(): void
    {
        $mw = new ThrottleMiddleware();
        $request1 = $this->makeRequest('10.99.0.4', '/api/users/123');
        $request2 = $this->makeRequest('10.99.0.4', '/api/users/456');

        $next = fn () => Response::success();
        $mw->handle($request1, $next, 1, 1);
        // Same normalized route -> blocked too
        $blocked = $mw->handle($request2, $next, 1, 1);
        $this->assertSame(429, $blocked->statusCode());
    }

    public function testNormalizePathPreservesNamedRoute(): void
    {
        $mw = new ThrottleMiddleware();
        $request1 = $this->makeRequest('10.99.0.5', '/api/profile');
        $request2 = $this->makeRequest('10.99.0.5', '/api/profile');

        $next = fn () => Response::success();
        $mw->handle($request1, $next, 1, 1);
        $blocked = $mw->handle($request2, $next, 1, 1);
        $this->assertSame(429, $blocked->statusCode());
    }

    public function testCorruptRateFileResets(): void
    {
        if (!is_dir($this->rateDir)) {
            mkdir($this->rateDir, 0775, true);
        }
        $ip = '10.99.0.6';
        $route = rawurlencode('GET:/api/corrupt');
        $key = hash('sha256', sprintf('rate:%s:%s', $ip, $route));
        file_put_contents($this->rateDir . DIRECTORY_SEPARATOR . $key . '.json', 'not-json{');

        $mw = new ThrottleMiddleware();
        $response = $mw->handle($this->makeRequest($ip, '/api/corrupt'), fn () => Response::success(), 1, 1);
        $this->assertSame(200, $response->statusCode());
    }

    public function testExpiredRateFileResets(): void
    {
        if (!is_dir($this->rateDir)) {
            mkdir($this->rateDir, 0775, true);
        }
        $ip = '10.99.0.7';
        $route = rawurlencode('GET:/api/expired');
        $key = hash('sha256', sprintf('rate:%s:%s', $ip, $route));
        file_put_contents($this->rateDir . DIRECTORY_SEPARATOR . $key . '.json', json_encode([
            'count' => 99,
            'expires_at' => time() - 100,
        ]));

        $mw = new ThrottleMiddleware();
        $response = $mw->handle($this->makeRequest($ip, '/api/expired'), fn () => Response::success(), 1, 1);
        $this->assertSame(200, $response->statusCode());
    }
}
