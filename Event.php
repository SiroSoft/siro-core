<?php

declare(strict_types=1);

namespace Siro\Core;

use Closure;

/**
 * Minimal event dispatcher (Phalcon-style).
 *
 * Supports string event names with wildcard patterns.
 * Return false from a listener to stop propagation.
 *
 * Usage:
 *   Event::listen('user.registered', fn(User $user) => ...);
 *   Event::dispatch('user.registered', $user);
 *
 * @package Siro\Core
 */
final class Event
{
    /** @var array<string, array<int, callable>> */
    private static array $listeners = [];

    /**
     * Register a listener for an event.
     *
     * Event names can use wildcards: 'user.*' matches 'user.registered', etc.
     *
     * @param string $event Event name or pattern (e.g., 'user.registered', 'user.*')
     * @param callable $callback Handler receiving ($eventName, $payload)
     */
    public static function listen(string $event, callable $callback): void
    {
        self::$listeners[$event][] = $callback;
    }

    /**
     * Dispatch an event to all matching listeners.
     *
     * If a listener returns false, propagation stops immediately.
     *
     * @param string $event Event name
     * @param mixed $payload Data passed to each listener
     * @return bool True if propagation completed, false if stopped
     */
    public static function dispatch(string $event, mixed $payload = null): bool
    {
        $normalized = strtolower(trim($event));

        foreach (self::$listeners as $pattern => $callbacks) {
            if (!self::matches($normalized, $pattern)) {
                continue;
            }

            foreach ($callbacks as $callback) {
                $result = $callback($event, $payload);
                if ($result === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Remove a specific listener, or all listeners for an event.
     *
     * @param string $event Event name
     * @param callable|null $callback Remove specific callback, or null to remove all
     */
    public static function remove(string $event, ?callable $callback = null): void
    {
        if ($callback === null) {
            unset(self::$listeners[$event]);
            return;
        }

        if (!isset(self::$listeners[$event])) {
            return;
        }

        foreach (self::$listeners[$event] as $i => $existing) {
            if ($existing === $callback) {
                array_splice(self::$listeners[$event], $i, 1);
                break;
            }
        }
    }

    /** @return array<string, array<int, callable>> */
    public static function getListeners(): array
    {
        return self::$listeners;
    }

    /** Check if an event name matches a pattern (supports wildcards). */
    private static function matches(string $event, string $pattern): bool
    {
        $normalizedPattern = strtolower(trim($pattern));

        // Exact match
        if ($normalizedPattern === $event) {
            return true;
        }

        // Wildcard: 'user.*' matches 'user.registered', 'user.login'
        if (str_ends_with($normalizedPattern, '.*')) {
            $prefix = substr($normalizedPattern, 0, -2);
            if ($prefix === '' || str_starts_with($event, $prefix . '.')) {
                return true;
            }
        }

        // Global wildcard: '*' matches everything
        if ($normalizedPattern === '*') {
            return true;
        }

        return false;
    }
}
