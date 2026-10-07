<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\AuthorizationException;
use Siro\Core\Gate;
use Siro\Core\Tests\TestCase;

/**
 * Gate abilities: define/allows/denies/authorize + before hooks.
 */
final class GateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::reset();
    }

    protected function tearDown(): void
    {
        Gate::reset();
        parent::tearDown();
    }

    public function testAllowsAndDenies(): void
    {
        Gate::define('products.create', fn (?array $user): bool => ($user['role'] ?? '') === 'admin');

        $this->assertTrue(Gate::allows('products.create', ['role' => 'admin']));
        $this->assertFalse(Gate::allows('products.create', ['role' => 'viewer']));
        $this->assertTrue(Gate::denies('products.create', ['role' => 'viewer']));
    }

    public function testUndefinedAbilityDenies(): void
    {
        $this->assertFalse(Gate::allows('never.defined', ['role' => 'admin']));
    }

    public function testAuthorizeThrowsWithForbiddenCode(): void
    {
        Gate::define('products.delete', fn (): bool => false);

        try {
            Gate::authorize('products.delete', ['role' => 'viewer']);
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->getCode());
            $response = $e->toResponse();
            $this->assertSame(403, $response->statusCode());
            $this->assertSame('forbidden', $response->payload()['meta']['error_code'] ?? null);
        }
    }

    public function testAuthorizePassesSilently(): void
    {
        Gate::define('products.view', fn (): bool => true);
        Gate::authorize('products.view');
        $this->assertTrue(true);
    }

    public function testBeforeHookGrantsSuperAdmin(): void
    {
        Gate::before(fn (?array $user): ?bool => ($user['role'] ?? '') === 'super-admin' ? true : null);
        Gate::define('products.delete', fn (): bool => false);

        $this->assertTrue(Gate::allows('products.delete', ['role' => 'super-admin']));
        $this->assertFalse(Gate::allows('products.delete', ['role' => 'admin']));
    }

    public function testBeforeHookCanVeto(): void
    {
        Gate::before(fn (): bool => false);
        Gate::define('products.view', fn (): bool => true);

        $this->assertFalse(Gate::allows('products.view'));
    }

    public function testActingAs(): void
    {
        Gate::define('products.create', fn (?array $user): bool => ($user['role'] ?? '') === 'admin');
        Gate::actingAs(['role' => 'admin']);

        $this->assertTrue(Gate::allows('products.create'));
        Gate::clearActingUser();
        $this->assertFalse(Gate::allows('products.create'));
    }

    public function testPolicyStyleCallable(): void
    {
        Gate::define('orders.view', [GateOrderPolicy::class, 'view']);

        $this->assertTrue(Gate::allows('orders.view', ['id' => 7], ['user_id' => 7]));
        $this->assertFalse(Gate::allows('orders.view', ['id' => 8], ['user_id' => 7]));
    }
}

final class GateOrderPolicy
{
    /** @param array<string, mixed> $user @param array<string, mixed> $order */
    public static function view(?array $user, array $order): bool
    {
        return ($user['id'] ?? null) === ($order['user_id'] ?? null);
    }
}
