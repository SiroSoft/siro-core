<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Http;
use Siro\Core\Webhook\WebhookDispatcher;

/**
 * Send one test webhook event through the dispatcher (G5, v1.5.0).
 *
 *   php siro webhook:test order.created
 *   php siro webhook:test order.created --url=https://example.com/hook --secret=s3cr3t
 *   php siro webhook:test order.created --payload='{"order_id": 1}'
 *   php siro webhook:test order.paid --url=http://127.0.0.1:8080/hook --secret=s3cr3t --allow-private-ip
 *
 * Without --url the event goes to every configured endpoint subscribed to
 * it (see `config/webhooks.php`). The command only queues the delivery —
 * the queue worker performs the actual HTTP call.
 *
 * Private/local targets (127.0.0.1, RFC 1918, …) are blocked by Http's
 * SSRF guard by default. Opt in explicitly with --allow-private-ip (saved
 * as WEBHOOK_ALLOW_PRIVATE_IP=true in the project's .env so the separate
 * queue:work process sees it) or by setting that env var yourself.
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
        $allowPrivateIp = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--url=')) {
                $url = substr($arg, 6);
            } elseif (str_starts_with($arg, '--secret=')) {
                $secret = substr($arg, 9);
            } elseif ($arg === '--allow-private-ip') {
                $allowPrivateIp = true;
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
        if ($allowPrivateIp) {
            $this->persistPrivateIpOptIn();
            $this->write('Private-IP webhooks allowed: WEBHOOK_ALLOW_PRIVATE_IP=true saved to .env.');
            $this->write('SSRF protection for private IPs is now off for webhook deliveries from this project.');
        } elseif ($url !== null && $url !== '' && self::isPrivateUrl($url) && !Http::isPrivateIpAllowed()) {
            $this->write('Note: ' . $url . ' is a private/local address. Delivery will be blocked by the SSRF guard');
            $this->write('unless WEBHOOK_ALLOW_PRIVATE_IP=true is set (or re-run with --allow-private-ip).');
        }
        return 0;
    }

    /**
     * Persist the explicit private-IP opt-in so the queue worker — a
     * separate process that cannot see this command's CLI flags — honors
     * it: the worker loads the project's .env on boot. Replaces an
     * existing WEBHOOK_ALLOW_PRIVATE_IP line instead of duplicating it.
     */
    private function persistPrivateIpOptIn(): void
    {
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        $line = 'WEBHOOK_ALLOW_PRIVATE_IP=true';
        if (is_file($envFile)) {
            $content = (string) file_get_contents($envFile);
            if (preg_match('/^WEBHOOK_ALLOW_PRIVATE_IP=.*$/m', $content) === 1) {
                $content = (string) preg_replace('/^WEBHOOK_ALLOW_PRIVATE_IP=.*$/m', $line, $content);
            } else {
                $content = rtrim($content) . PHP_EOL . PHP_EOL
                    . '# Webhook deliveries to private/local IPs are allowed' . PHP_EOL
                    . '# (opted in via webhook:test --allow-private-ip).' . PHP_EOL
                    . $line . PHP_EOL;
            }
            file_put_contents($envFile, $content);
        }
        $_ENV['WEBHOOK_ALLOW_PRIVATE_IP'] = 'true';
        $_SERVER['WEBHOOK_ALLOW_PRIVATE_IP'] = 'true';
        putenv('WEBHOOK_ALLOW_PRIVATE_IP=true');
    }

    /**
     * Best-effort check whether a URL points at a private/local address
     * (IP literal or a hostname resolving to one). Only used for the
     * hint printed after queueing; the worker's Http guard is the
     * authoritative check.
     */
    private static function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $ips = gethostbynamel($host);
        if ($ips === false) {
            $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [];
        }
        foreach ($ips as $ip) {
            if ($ip === '::1') {
                return true;
            }
            $isIp = filter_var($ip, FILTER_VALIDATE_IP) !== false;
            $noPrivOrRes = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            $isPublic = filter_var($ip, FILTER_VALIDATE_IP, $noPrivOrRes) !== false;
            if ($isIp && !$isPublic) {
                return true;
            }
        }
        return false;
    }
}
