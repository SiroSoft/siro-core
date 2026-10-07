<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Simple authorization gate: named abilities backed by callables.
 *
 * Define abilities in a service provider or bootstrap:
 *   Gate::define('products.create', fn (?array $user): bool => ($user['role'] ?? '') === 'admin');
 *   Gate::define('orders.view', [OrderPolicy::class, 'view']);
 *
 * Check in controllers or middleware:
 *   Gate::authorize('products.create', $request->user());
 *   if (Gate::denies('orders.view', $user, $order)) { ... }
 *
 * before() hooks run first and can grant (return true) or veto (return
 * false) before the ability callback; return null to continue. Typical use:
 * super-admin bypass.
 *
 * @package Siro\Core
 */
final class Gate
{
    /** @var array<string, callable> */
    private static array $abilities = [];

    /** @var array<int, callable> */
    private static array $beforeHooks = [];

    private static mixed $actingAs = null;
    private static bool $hasActingUser = false;

    public static function define(string $ability, callable $callback): void
    {
        self::$abilities[$ability] = $callback;
    }

    public static function has(string $ability): bool
    {
        return isset(self::$abilities[$ability]);
    }

    public static function before(callable $hook): void
    {
        self::$beforeHooks[] = $hook;
    }

    public static function actingAs(mixed $user): void
    {
        self::$actingAs = $user;
        self::$hasActingUser = true;
    }

    public static function clearActingUser(): void
    {
        self::$actingAs = null;
        self::$hasActingUser = false;
    }

    /**
     * Clear abilities, hooks and acting user. Required for test isolation.
     */
    public static function reset(): void
    {
        self::$abilities = [];
        self::$beforeHooks = [];
        self::clearActingUser();
    }

    public static function allows(string $ability, mixed $user = null, mixed ...$args): bool
    {
        $user ??= self::$hasActingUser ? self::$actingAs : null;

        foreach (self::$beforeHooks as $hook) {
            $result = $hook($user, ...$args);
            if ($result === true) {
                return true;
            }
            if ($result === false) {
                return false;
            }
        }

        $callback = self::$abilities[$ability] ?? null;
        if ($callback === null) {
            return false;
        }

        return (bool) $callback($user, ...$args);
    }

    public static function denies(string $ability, mixed $user = null, mixed ...$args): bool
    {
        return !self::allows($ability, $user, ...$args);
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(string $ability, mixed $user = null, mixed ...$args): void
    {
        if (self::denies($ability, $user, ...$args)) {
            throw new AuthorizationException("Forbidden: missing ability [{$ability}].");
        }
    }
}
