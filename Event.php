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
 *   $handle = Event::on('payment.captured', $moduleListener);
 *   Event::off('payment.captured', $handle); // removes only this listener
 *
 * @package Siro\Core
 */
final class Event
{
    private static ?Event $instance = null;

    /** @var array<string, array<int, array{handle: int, callback: callable, once: bool}>> */
    private array $listeners = [];
    private string $currentEvent = '';
    private int $nextHandle = 1;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?Event $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Register an event listener.
     *
     * @return int Listener handle for selective removal via off().
     */
    public static function on(string $event, callable $callback): int
    {
        return self::instance()->addListener($event, $callback, false);
    }

    /**
     * Register a one-time event listener.
     *
     * @return int Listener handle for selective removal via off().
     */
    public static function once(string $event, callable $callback): int
    {
        return self::instance()->addListener($event, $callback, true);
    }

    /**
     * Remove listeners for an event (or use wildcard).
     *
     * When $target is null, all listeners for the event are removed (legacy
     * behavior). Pass a listener handle returned by on()/once() to remove
     * only that listener, or a callable to remove matching callbacks only.
     *
     * @param int|callable|null $target Listener handle, callback, or null for all.
     */
    public static function off(string $event, int|callable|null $target = null): void
    {
        self::instance()->removeListeners($event, $target);
    }

    /**
     * Fire an event, calling all registered listeners.
     */
    public static function emit(string $event, mixed $payload = null): bool
    {
        $instance = self::$instance;
        if ($instance === null || $instance->listeners === []) {
            return true;
        }
        if (!isset($instance->listeners[$event]) && !str_contains($event, '*')) {
            $hasWildcard = false;
            foreach ($instance->listeners as $key => $val) {
                if (str_contains($key, '*')) { $hasWildcard = true; break; }
            }
            if (!$hasWildcard) return true;
        }
        return $instance->dispatch($event, $payload);
    }

    /**
     * Check if an event has listeners.
     */
    public static function hasListeners(string $event): bool
    {
        return self::instance()->hasListenersFor($event);
    }

    /**
     * Get the current event name being emitted.
     */
    public static function currentEvent(): string
    {
        return self::instance()->currentEvent;
    }

    /**
     * Remove all listeners.
     */
    public static function flush(): void
    {
        $instance = self::$instance;
        if ($instance !== null) {
            $instance->listeners = [];
        }
    }

    /**
     * Bulk-replace listeners (testing/seeding). Entries without a handle
     * (legacy shape) are assigned fresh handles automatically.
     *
     * @param array<string, array<int, array{handle?: int, callback: callable, once: bool}>> $listeners
     */
    public static function setListeners(array $listeners): void
    {
        $instance = self::instance();
        $normalized = [];
        foreach ($listeners as $event => $entries) {
            foreach ($entries as $entry) {
                $normalized[$event][] = [
                    'handle' => $entry['handle'] ?? $instance->nextHandle++,
                    'callback' => $entry['callback'],
                    'once' => $entry['once'],
                ];
            }
        }
        $instance->listeners = $normalized;
    }

    private function addListener(string $event, callable $callback, bool $once): int
    {
        $handle = $this->nextHandle++;
        $this->listeners[$event][] = [
            'handle' => $handle,
            'callback' => $callback,
            'once' => $once,
        ];
        $this->wildcardIndex = null;
        return $handle;
    }

    /**
     * @param int|callable|null $target Listener handle, callback, or null for all.
     */
    private function removeListeners(string $event, int|callable|null $target = null): void
    {
        if ($target === null) {
            if (str_contains($event, '*')) {
                $pattern = '/^' . str_replace('\\*', '.*', preg_quote($event, '/')) . '$/';
                foreach (array_keys($this->listeners) as $key) {
                    if (preg_match($pattern, $key)) {
                        unset($this->listeners[$key]);
                    }
                }
            } else {
                unset($this->listeners[$event]);
            }
            $this->wildcardIndex = null;
            return;
        }

        $keys = [ $event ];
        if (str_contains($event, '*')) {
            $pattern = '/^' . str_replace('\\*', '.*', preg_quote($event, '/')) . '$/';
            $keys = [];
            foreach (array_keys($this->listeners) as $key) {
                if (preg_match($pattern, $key)) {
                    $keys[] = $key;
                }
            }
        }

        foreach ($keys as $key) {
            if (!isset($this->listeners[$key])) {
                continue;
            }
            $this->listeners[$key] = array_values(array_filter(
                $this->listeners[$key],
                static fn(array $l): bool => is_int($target)
                    ? $l['handle'] !== $target
                    : $l['callback'] !== $target
            ));
            if ($this->listeners[$key] === []) {
                unset($this->listeners[$key]);
            }
        }
        $this->wildcardIndex = null;
    }

    private function dispatch(string $event, mixed $payload): bool
    {
        $this->currentEvent = $event;
        $matched = $this->getListeners($event);

        foreach ($matched as $listener) {
            $result = ($listener['callback'])($payload);

            if ($result === false) {
                return false;
            }
        }

        // Cleanup one-time listeners registered under concrete events
        if (isset($this->listeners[$event])) {
            $this->listeners[$event] = array_filter(
                $this->listeners[$event],
                fn(array $l): bool => !$l['once']
            );
            if ($this->listeners[$event] === []) {
                unset($this->listeners[$event]);
            }
        }

        return true;
    }

    /** @var array<string, array<int, array{handle: int, callback: callable, once: bool}>>|null */
    private ?array $wildcardIndex = null;

    private function buildWildcardIndex(): void
    {
        $this->wildcardIndex = [];
        foreach ($this->listeners as $key => $listeners) {
            if (!str_contains($key, '*')) {
                continue;
            }
            $pattern = '/^' . str_replace('\\*', '.*', preg_quote($key, '/')) . '$/';
            $this->wildcardIndex[$pattern] = $listeners;
        }
    }

    /** @return array<int, array{handle: int, callback: callable, once: bool}> */
    private function getListeners(string $event): array
    {
        $matched = $this->listeners[$event] ?? [];

        if ($this->wildcardIndex === null) {
            $this->buildWildcardIndex();
        }

        if (is_array($this->wildcardIndex)) {
            foreach ($this->wildcardIndex as $pattern => $listeners) {
                if (preg_match($pattern, $event)) {
                    foreach ($listeners as $listener) {
                        $matched[] = $listener;
                    }
                }
            }
        }

        return $matched;
    }

    private function hasListenersFor(string $event): bool
    {
        return $this->getListeners($event) !== [];
    }
}
