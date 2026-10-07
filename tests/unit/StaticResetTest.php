<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Metrics;
use Siro\Core\Middleware\VersionMiddleware;
use Siro\Core\Route;
use Siro\Core\Router;
use Siro\Core\Tests\TestCase;
use Siro\Core\Validator;

/**
 * Static registry resets for test isolation (Issue 5).
 */
final class StaticResetTest extends TestCase
{
    protected function tearDown(): void
    {
        Router::resetStatic();
        Route::resetNamedRoutes();
        Validator::resetCustomizations();
        Metrics::reset();
        VersionMiddleware::reset();
        parent::tearDown();
    }

    public function testRouterResetStaticClearsAliasesAndPriorities(): void
    {
        Router::registerMiddlewareAlias('probe', \Siro\Core\Middleware\CorsMiddleware::class);
        Router::setMiddlewarePriority('probe', 5);
        $this->assertNotEmpty(Router::getMiddlewareAliases());

        Router::resetStatic();

        $this->assertSame([], Router::getMiddlewareAliases());
        $this->assertSame([], Router::getMiddlewarePriorities());
    }

    public function testValidatorResetClearsCustomizations(): void
    {
        Validator::extend('probe_rule', static fn(): bool => true);
        Validator::messages(['probe_rule' => 'Probe message']);

        Validator::resetCustomizations();

        $errors = Validator::make(['x' => 'y'], ['x' => 'probe_rule']);
        $this->assertSame([], $errors, 'Unknown rule must be ignored after reset');
    }

    public function testMetricsResetClearsSeries(): void
    {
        Metrics::counter('probe_total', 3);
        Metrics::reset();

        $this->assertStringNotContainsString('probe_total', Metrics::export());
    }

    public function testVersionMiddlewareResetRestoresDefaults(): void
    {
        VersionMiddleware::register(3, '/api/v3');
        VersionMiddleware::override(3, 'GET', '/probe', static fn() => null);
        VersionMiddleware::reset();

        $request = new \Siro\Core\Request('GET', '/probe', [], ['accept' => 'application/vnd.siro.v3+json']);
        // v3 unknown after reset -> falls back to default latest (1)
        $this->assertSame(1, VersionMiddleware::getVersion($request));
        $this->assertNull(VersionMiddleware::resolveOverride(3, 'GET', '/probe'));
    }
}
