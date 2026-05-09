<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Lightweight publish/subscribe event dispatcher.
 *
 * Supports named events with multiple listeners, wildcards,
 * one-time listeners, and listener removal.
 *
 * Usage:
 *   Event::on('user.created', function ($user) { ... });
 *   Event::on('user.*', function ($event, $payload) { ... });
 *   Event::emit('user.created', $user);
 *
 * @package Siro\Core
 */
final class Event
{
    /** @var array<string, array<int, array{callback: callable, once: bool}>> */
    private static array $listeners = [];
    private static string $currentEvent = '';

    /**
     * Register an event listener.
     *
     * @param string $event Event name (e.g. "user.created", "user.*")
     * @param callable $callback Listener: fn($payload) or fn($event, $payload)
     */
    public static function on(string $event, callable $callback): void
    {
        self::$listeners[$event][] = [
            'callback' => $callback,
            'once' => false,
        ];
    }

    /**
     * Register a one-time event listener.
     * Removed after being fired once.
     */
    public static function once(string $event, callable $callback): void
    {
        self::$listeners[$event][] = [
            'callback' => $callback,
            'once' => true,
        ];
    }

    /**
     * Remove all listeners for an event (or use wildcard).
     */
    public static function off(string $event): void
    {
        if (str_contains($event, '*')) {
            $pattern = '/^' . str_replace('\\*', '.*', preg_quote($event, '/')) . '$/';
            foreach (array_keys(self::$listeners) as $key) {
                if (preg_match($pattern, $key)) {
                    unset(self::$listeners[$key]);
                }
            }
        } else {
            unset(self::$listeners[$event]);
        }
    }

    /**
     * Fire an event, calling all registered listeners.
     *
     * @param string $event Event name
     * @param mixed $payload Data passed to listeners
     * @return bool False if a listener returned false (halt), true otherwise
     */
    public static function emit(string $event, mixed $payload = null): bool
    {
        self::$currentEvent = $event;
        $matched = self::getListeners($event);

        foreach ($matched as $index => $listener) {
            $result = ($listener['callback'])($payload);

            if ($listener['once']) {
                unset(self::$listeners[$event][$index]);
            }

            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all listeners matching an event (exact + wildcard).
     *
     * @return array<int, array{callback: callable, once: bool}>
     */
    private static function getListeners(string $event): array
    {
        $matched = self::$listeners[$event] ?? [];

        foreach (self::$listeners as $key => $listeners) {
            if ($key === $event || !str_contains($key, '*')) {
                continue;
            }

            $pattern = '/^' . str_replace('\\*', '.*', preg_quote($key, '/')) . '$/';
            if (preg_match($pattern, $event)) {
                foreach ($listeners as $listener) {
                    $matched[] = $listener;
                }
            }
        }

        return $matched;
    }

    /**
     * Check if an event has listeners.
     */
    public static function hasListeners(string $event): bool
    {
        return self::getListeners($event) !== [];
    }

    /**
     * Get the current event name being emitted.
     */
    public static function currentEvent(): string
    {
        return self::$currentEvent;
    }

    /**
     * Remove all listeners.
     */
    public static function flush(): void
    {
        self::$listeners = [];
    }
}
