<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use InvalidArgumentException;
use Siro\Core\Middleware\ThrottleMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Tests\TestCase;

final class ThrottleTierTest extends TestCase
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
        ThrottleMiddleware::reset();
        $this->middleware = new ThrottleMiddleware();
        $this->rateDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';
        $this->cleanRateFiles();
    }

    protected function tearDown(): void
    {
        // setUp forces REDIS_PORT=1; clear it from both putenv and $_ENV
        // (Env::get() reads $_ENV first) or later redis tests break.
        putenv('REDIS_PORT');
        putenv('THROTTLE_FALLBACK');
        unset($_ENV['REDIS_PORT'], $_ENV['THROTTLE_FALLBACK']);
        $this->cleanRateFiles();
        ThrottleMiddleware::reset();
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

    private function makeRequest(string $ip = '127.0.0.1', string $path = '/api/test', ?array $user = null): Request
    {
        $request = new Request('GET', $path, [], [], [], $ip);
        if ($user !== null) {
            $request->setUser($user);
        }
        return $request;
    }

    public function testDefineAndGetLimiter(): void
    {
        ThrottleMiddleware::define('pos', 600, 1, 'user');
        $limiter = ThrottleMiddleware::getLimiter('pos');
        $this->assertNotNull($limiter);
        $this->assertSame(600, $limiter['limit']);
        $this->assertSame(1, $limiter['minutes']);
        $this->assertSame('user', $limiter['key']);
        $this->assertNull(ThrottleMiddleware::getLimiter('nope'));
    }

    public function testDefineValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ThrottleMiddleware::define('', 10, 1);
    }

    public function testDefineValidationZeroLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ThrottleMiddleware::define('x', 0, 1);
    }

    public function testDefineValidationBadKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ThrottleMiddleware::define('x', 10, 1, 'carrier-pigeon');
    }

    public function testDefinePlanValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ThrottleMiddleware::definePlan('', 'partner');
    }

    public function testUnknownLimiterThrowsFailFast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->middleware->handle($this->makeRequest(), fn () => Response::success(), 'nope');
    }

    public function testThreeTiersHaveIndependentCounters(): void
    {
        ThrottleMiddleware::define('pos', 600, 1);
        ThrottleMiddleware::define('partner', 60, 1);
        ThrottleMiddleware::define('public', 10, 1);
        $next = fn () => Response::success();

        // Exhaust the public tier (10/min) from one IP.
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(200, $this->middleware->handle($this->makeRequest(), $next, 'public')->statusCode());
        }
        $this->assertSame(429, $this->middleware->handle($this->makeRequest(), $next, 'public')->statusCode());

        // The same IP is still fine on the other tiers — separate counters.
        $this->assertSame(200, $this->middleware->handle($this->makeRequest(), $next, 'pos')->statusCode());
        $this->assertSame(200, $this->middleware->handle($this->makeRequest(), $next, 'partner')->statusCode());
    }

    public function testUserKeyStrategySeparatesUsers(): void
    {
        ThrottleMiddleware::define('byuser', 2, 1, 'user');
        $next = fn () => Response::success();

        $user7 = ['id' => 7, 'type' => 'user'];
        $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $user7), $next, 'byuser')->statusCode());
        $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $user7), $next, 'byuser')->statusCode());
        $this->assertSame(429, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $user7), $next, 'byuser')->statusCode());

        // A different user on the same IP has a fresh counter.
        $user8 = ['id' => 8, 'type' => 'user'];
        $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $user8), $next, 'byuser')->statusCode());
    }

    public function testApiKeyStrategySeparatesKeys(): void
    {
        ThrottleMiddleware::define('bykey', 1, 1, 'api_key');
        $next = fn () => Response::success();

        $key5 = ['id' => 9, 'type' => 'api_key', 'key_id' => 5];
        $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $key5), $next, 'bykey')->statusCode());
        $this->assertSame(429, $this->middleware->handle($this->makeRequest('10.0.0.2', '/api/test', $key5), $next, 'bykey')->statusCode());

        $key6 = ['id' => 9, 'type' => 'api_key', 'key_id' => 6];
        $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $key6), $next, 'bykey')->statusCode());
    }

    public function testCallableKeyStrategy(): void
    {
        ThrottleMiddleware::define('tenant', 1, 1, static fn (Request $r): string => 'tenant-7');
        $next = fn () => Response::success();

        $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.1'), $next, 'tenant')->statusCode());
        // A different IP shares the counter: the key comes from the resolver.
        $this->assertSame(429, $this->middleware->handle($this->makeRequest('10.0.0.2'), $next, 'tenant')->statusCode());
    }

    public function testPlanMappingSwitchesLimiter(): void
    {
        ThrottleMiddleware::define('api', 600, 1);
        ThrottleMiddleware::define('partner', 3, 1);
        ThrottleMiddleware::definePlan('partner', 'partner');
        $next = fn () => Response::success();

        $partnerUser = ['id' => 1, 'type' => 'api_key', 'key_id' => 1, 'plan' => 'partner'];
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $partnerUser), $next, 'api')->statusCode());
        }
        // 4th request hits the partner tier (3/min), not the api tier (600/min).
        $this->assertSame(429, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $partnerUser), $next, 'api')->statusCode());

        // No plan: the route limiter applies.
        $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.9'), $next, 'api')->statusCode());
    }

    public function testPlanMappingToUndefinedLimiterFallsBack(): void
    {
        ThrottleMiddleware::define('api', 1, 1);
        ThrottleMiddleware::definePlan('gold', 'nope');
        $next = fn () => Response::success();

        $goldUser = ['id' => 1, 'type' => 'api_key', 'key_id' => 1, 'plan' => 'gold'];
        $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $goldUser), $next, 'api')->statusCode());
        $this->assertSame(429, $this->middleware->handle($this->makeRequest('10.0.0.1', '/api/test', $goldUser), $next, 'api')->statusCode());
    }

    public function testLegacyNumericLimitStillWorks(): void
    {
        $next = fn () => Response::success();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->middleware->handle($this->makeRequest('10.0.0.5'), $next, 5, 1)->statusCode());
        }
        $this->assertSame(429, $this->middleware->handle($this->makeRequest('10.0.0.5'), $next, 5, 1)->statusCode());
    }

    public function testSuccessAnd429CarryAllFourHeaders(): void
    {
        ThrottleMiddleware::define('tiny', 2, 1);
        $next = fn () => Response::success();

        $ok = $this->middleware->handle($this->makeRequest('10.0.0.7'), $next, 'tiny');
        $this->assertSame(200, $ok->statusCode());
        $this->assertSame('2', $ok->getHeader('X-RateLimit-Limit'));
        $this->assertSame('1', $ok->getHeader('X-RateLimit-Remaining'));
        $this->assertNotNull($ok->getHeader('X-RateLimit-Reset'));
        $this->assertNotNull($ok->getHeader('Retry-After'));
        $this->assertGreaterThan(time(), (int) ($ok->getHeader('X-RateLimit-Reset') ?? '0'));

        $this->middleware->handle($this->makeRequest('10.0.0.7'), $next, 'tiny');
        $blocked = $this->middleware->handle($this->makeRequest('10.0.0.7'), $next, 'tiny');
        $this->assertSame(429, $blocked->statusCode());
        $this->assertSame('2', $blocked->getHeader('X-RateLimit-Limit'));
        $this->assertSame('0', $blocked->getHeader('X-RateLimit-Remaining'));
        $this->assertNotNull($blocked->getHeader('X-RateLimit-Reset'));
        $retryAfter = (int) ($blocked->getHeader('Retry-After') ?? '0');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function testResetClearsRegistry(): void
    {
        ThrottleMiddleware::define('x', 5, 1);
        ThrottleMiddleware::reset();
        $this->assertSame([], ThrottleMiddleware::limiters());
        $this->expectException(InvalidArgumentException::class);
        $this->middleware->handle($this->makeRequest(), fn () => Response::success(), 'x');
    }

    public function testDefineOverwritesSameName(): void
    {
        ThrottleMiddleware::define('x', 5, 1);
        ThrottleMiddleware::define('x', 2, 1);
        $this->assertSame(2, ThrottleMiddleware::getLimiter('x')['limit']);
    }
}
