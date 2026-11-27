<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Webhook\WebhookDispatcher;

/**
 * Send one test webhook event through the dispatcher (G5, v1.5.0).
 *
 *   php siro webhook:test order.created
 *   php siro webhook:test order.created --url=https://example.com/hook --secret=s3cr3t
 *   php siro webhook:test order.created --payload='{"order_id": 1}'
 *
 * Without --url the event goes to every configured endpoint subscribed to
 * it (see `config/webhooks.php`). The command only queues the delivery —
 * the queue worker performs the actual HTTP call.
 */
final class WebhookTestCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $event = trim((string) ($args[0] ?? ''));
        if ($event === '') {
            $this->write('Event name is required. Example: php siro webhook:test order.created');
            return 1;
        }

        $url = null;
        $secret = null;
        $payload = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--url=')) {
                $url = substr($arg, 6);
            } elseif (str_starts_with($arg, '--secret=')) {
                $secret = substr($arg, 9);
            } elseif (str_starts_with($arg, '--payload=')) {
                $decoded = json_decode(substr($arg, 10), true);
                if (!is_array($decoded)) {
                    $this->error('Invalid --payload JSON: must decode to an object/array.');
                    return 1;
                }
                /** @var array<string, mixed> $decoded */
                $payload = $decoded;
            }
        }

        // Boot the app (same pattern as queue:work): loads Env + Config
        // (including config/webhooks.php) and configures the Database,
        // which Queue::push needs.
        (new App($this->basePath))->boot();
        $dispatcher = WebhookDispatcher::fromConfig();
        try {
            $queued = $dispatcher->dispatch($event, $payload, $url !== '' ? $url : null, $secret);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if ($queued === 0) {
            $this->error("No endpoint is subscribed to event '{$event}'. Add one in config/webhooks.php or pass --url=.");
            return 1;
        }

        $target = $url !== null && $url !== ''
            ? $url
            : $queued . ' configured endpoint(s)';
        $this->write("Queued {$queued} webhook deliver" . ($queued === 1 ? 'y' : 'ies') . " for event '{$event}' → {$target}");
        $this->write('Run `php siro queue:work` to deliver.');
        return 0;
    }
}
