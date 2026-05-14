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
    private static ?Event $instance = null;

    /** @var array<string, array<int, array{callback: callable, once: bool}>> */
    private array $listeners = [];
    private string $currentEvent = '';

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
     */
    public static function on(string $event, callable $callback): void
    {
        self::instance()->addListener($event, $callback, false);
    }

    /**
     * Register a one-time event listener.
     */
    public static function once(string $event, callable $callback): void
    {
        self::instance()->addListener($event, $callback, true);
    }

    /**
     * Remove all listeners for an event (or use wildcard).
     */
    public static function off(string $event): void
    {
        self::instance()->removeListeners($event);
    }

    /**
     * Fire an event, calling all registered listeners.
     */
    public static function emit(string $event, mixed $payload = null): bool
    {
        return self::instance()->dispatch($event, $payload);
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

    /** @param array<string, array<int, array{callback: callable, once: bool}>> $listeners */
    public static function setListeners(array $listeners): void
    {
        self::instance()->listeners = $listeners;
    }

    private function addListener(string $event, callable $callback, bool $once): void
    {
        $this->listeners[$event][] = [
            'callback' => $callback,
            'once' => $once,
        ];
        $this->wildcardIndex = null;
    }

    private function removeListeners(string $event): void
    {
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
    }

    private function dispatch(string $event, mixed $payload): bool
    {
        $this->currentEvent = $event;
        $matched = $this->getListeners($event);

        foreach ($matched as $index => $listener) {
            $result = ($listener['callback'])($payload);

            if ($listener['once']) {
                unset($this->listeners[$event][$index]);
            }

            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array{callback: callable, once: bool}> */
    /** @var array<string, array<int, array{callback: callable, once: bool}>>|null */
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

    /** @return array<int, array{callback: callable, once: bool}> */
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
