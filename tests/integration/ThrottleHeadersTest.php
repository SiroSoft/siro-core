<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Siro\Core\Config;
use Siro\Core\Env;
use Siro\Core\Middleware\ThrottleMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Route;
use Siro\Core\Router;

final class ThrottleHeadersTest extends TestCase
{
    private Router $router;
    private string $rateDir;

    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        Env::reset();
        $_ENV['THROTTLE_FALLBACK'] = 'file';
        putenv('THROTTLE_FALLBACK=file');
        $_ENV['REDIS_PORT'] = '1';
        putenv('REDIS_PORT=1');
        ThrottleMiddleware::reset();
        Router::resetStatic();
        Router::registerMiddlewareAlias('throttle', ThrottleMiddleware::class);
        $this->router = new Router();
        Route::setRouter($this->router);
        Route::clearRoutes();
        $this->rateDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';
        $this->cleanRateFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanRateFiles();
        ThrottleMiddleware::reset();
        Router::resetStatic();
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

    private function dispatch(string $path, string $ip = '127.0.0.1', ?array $user = null): Response
    {
        $request = new Request('GET', $path, [], [], [], $ip);
        if ($user !== null) {
            $request->setUser($user);
        }
        $response = $this->router->dispatch($request);
        $this->assertInstanceOf(Response::class, $response);
        return $response;
    }

    public function testTierViaMiddlewareString(): void
    {
        ThrottleMiddleware::define('partner', 2, 1);
        $this->router->get('/api/partner', fn () => Response::success(['ok' => true]), ['throttle:partner']);

        $first = $this->dispatch('/api/partner');
        $this->assertSame(200, $first->statusCode());
        $this->assertSame('2', $first->getHeader('X-RateLimit-Limit'));
        $this->assertSame('1', $first->getHeader('X-RateLimit-Remaining'));
        $this->assertNotNull($first->getHeader('X-RateLimit-Reset'));
        $this->assertNotNull($first->getHeader('Retry-After'));

        $this->assertSame(200, $this->dispatch('/api/partner')->statusCode());

        $blocked = $this->dispatch('/api/partner');
        $this->assertSame(429, $blocked->statusCode());
        $this->assertSame('2', $blocked->getHeader('X-RateLimit-Limit'));
        $this->assertSame('0', $blocked->getHeader('X-RateLimit-Remaining'));
        $this->assertNotNull($blocked->getHeader('X-RateLimit-Reset'));
        $this->assertNotNull($blocked->getHeader('Retry-After'));
    }

    public function testLegacyNumericMiddlewareString(): void
    {
        $this->router->get('/api/legacy', fn () => Response::success(), ['throttle:2,1']);
        $this->assertSame(200, $this->dispatch('/api/legacy')->statusCode());
        $this->assertSame(200, $this->dispatch('/api/legacy')->statusCode());
        $this->assertSame(429, $this->dispatch('/api/legacy')->statusCode());
    }

    public function testUnknownLimiterThrowsOnDispatch(): void
    {
        $this->router->get('/api/nope', fn () => Response::success(), ['throttle:nope']);
        $this->expectException(InvalidArgumentException::class);
        $this->dispatch('/api/nope');
    }

    public function testPlanTierThroughRouter(): void
    {
        ThrottleMiddleware::define('api', 600, 1);
        ThrottleMiddleware::define('partner', 2, 1);
        ThrottleMiddleware::definePlan('partner', 'partner');
        $this->router->get('/api/data', fn () => Response::success(), ['throttle:api']);

        $partnerUser = ['id' => 1, 'type' => 'api_key', 'key_id' => 1, 'plan' => 'partner'];
        $this->assertSame(200, $this->dispatch('/api/data', '10.0.0.1', $partnerUser)->statusCode());
        $this->assertSame(200, $this->dispatch('/api/data', '10.0.0.1', $partnerUser)->statusCode());
        $this->assertSame(429, $this->dispatch('/api/data', '10.0.0.1', $partnerUser)->statusCode());

        // A request without a plan uses the route limiter (600/min).
        $this->assertSame(200, $this->dispatch('/api/data', '10.0.0.9')->statusCode());
    }

    public function testRateLimitFilesRecordTier(): void
    {
        ThrottleMiddleware::define('partner', 5, 1);
        $this->router->get('/api/partner', fn () => Response::success(), ['throttle:partner']);
        $this->dispatch('/api/partner');

        $files = glob($this->rateDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $this->assertCount(1, $files);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($files[0]), true);
        $this->assertSame('partner', $data['limiter']);
        $this->assertSame(5, $data['limit']);
        $this->assertSame(1, $data['count']);
    }
}
