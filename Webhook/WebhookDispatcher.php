<?php

declare(strict_types=1);

namespace Siro\Core\Webhook;

use InvalidArgumentException;
use Siro\Core\Config;
use Siro\Core\Queue;

/**
 * Dispatches webhook events to configured endpoints via the Queue (G5, v1.5.0).
 *
 * Config shape (see `config/webhooks.php`):
 *   endpoints      list<array{url: string, secret?: string, events?: list<string>}>
 *   default_secret string   Fallback secret when an endpoint has none
 *   max_attempts   int      Queue retries per delivery (default 3)
 *   timeout        int      HTTP timeout per delivery in seconds (default 10)
 *
 * An endpoint with an empty `events` list (or no `events` key) receives
 * every event; otherwise it only receives the listed events. The job class
 * is registered with the Queue whitelist on every dispatch — Queue only
 * runs whitelisted jobs, and this is the public API for that (Queue itself
 * is not modified by this lane).
 *
 * @package Siro\Core\Webhook
 */
final class WebhookDispatcher
{
    public const int DEFAULT_MAX_ATTEMPTS = 3;

    /** @var list<array{url: string, secret: string, events: list<string>}> */
    private array $endpoints;

    private string $defaultSecret;

    private int $maxAttempts;

    private int $timeout;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->defaultSecret = isset($config['default_secret']) && is_string($config['default_secret'])
            ? $config['default_secret']
            : '';
        $this->maxAttempts = isset($config['max_attempts']) && is_numeric($config['max_attempts'])
            ? max(1, (int) $config['max_attempts'])
            : self::DEFAULT_MAX_ATTEMPTS;
        $this->timeout = isset($config['timeout']) && is_numeric($config['timeout'])
            ? max(1, (int) $config['timeout'])
            : WebhookDeliveryJob::DEFAULT_TIMEOUT;
        $this->endpoints = self::normalizeEndpoints($config['endpoints'] ?? []);
    }

    /** Build a dispatcher from the `webhooks` config file (`config/webhooks.php`). */
    public static function fromConfig(): self
    {
        $config = Config::get('webhooks', []);
        if (!is_array($config)) {
            return new self([]);
        }
        // Config::get() returns array<mixed> — normalize keys to strings
        // so the constructor's array<string, mixed> contract holds.
        $normalized = [];
        foreach ($config as $key => $value) {
            $normalized[(string) $key] = $value;
        }
        return new self($normalized);
    }

    /**
     * Queue one delivery per subscribed endpoint and return how many were queued.
     *
     * With `$url` given, the event goes to that single ad-hoc endpoint
     * (secret = `$secret ?? default_secret`); otherwise it goes to every
     * configured endpoint subscribed to `$event`.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException When the URL is not http(s) or the
     *     resolved secret is empty — an unsigned delivery is never queued.
     */
    public function dispatch(string $event, array $payload, ?string $url = null, ?string $secret = null): int
    {
        if ($url !== null) {
            $resolvedSecret = $secret ?? $this->defaultSecret;
            $this->queueDelivery($url, $resolvedSecret, $event, $payload);
            return 1;
        }

        $queued = 0;
        foreach ($this->subscribedEndpoints($event) as $endpoint) {
            $this->queueDelivery($endpoint['url'], $endpoint['secret'], $event, $payload);
            $queued++;
        }
        return $queued;
    }

    /**
     * Configured endpoints subscribed to an event (empty `events` = all events).
     *
     * @return list<array{url: string, secret: string, events: list<string>}>
     */
    public function subscribedEndpoints(string $event): array
    {
        $matched = [];
        foreach ($this->endpoints as $endpoint) {
            if ($endpoint['events'] === [] || in_array($event, $endpoint['events'], true)) {
                $matched[] = $endpoint;
            }
        }
        return $matched;
    }

    /** @param array<string, mixed> $payload */
    private function queueDelivery(string $url, string $secret, string $event, array $payload): void
    {
        if (!self::isHttpUrl($url)) {
            throw new InvalidArgumentException("Webhook endpoint url must be http(s), '{$url}' given.");
        }
        if ($secret === '') {
            throw new InvalidArgumentException("Webhook endpoint '{$url}' has no secret configured.");
        }
        Queue::registerJob(WebhookDeliveryJob::class);
        Queue::push(WebhookDeliveryJob::class, [
            'url' => $url,
            'event' => $event,
            'payload' => $payload,
            'secret' => $secret,
            'timeout' => $this->timeout,
        ], 0, 0, $this->maxAttempts, $this->timeout);
    }

    /**
     * @param mixed $raw
     * @return list<array{url: string, secret: string, events: list<string>}>
     */
    private static function normalizeEndpoints(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $endpoints = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = isset($entry['url']) && is_string($entry['url']) ? $entry['url'] : '';
            if ($url === '') {
                continue;
            }
            $secret = isset($entry['secret']) && is_string($entry['secret']) ? $entry['secret'] : '';
            $events = [];
            if (isset($entry['events']) && is_array($entry['events'])) {
                foreach ($entry['events'] as $event) {
                    if (is_string($event) && $event !== '') {
                        $events[] = $event;
                    }
                }
            }
            $endpoints[] = ['url' => $url, 'secret' => $secret, 'events' => $events];
        }
        return $endpoints;
    }

    private static function isHttpUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }
}
