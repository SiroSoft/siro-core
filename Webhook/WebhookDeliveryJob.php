<?php

declare(strict_types=1);

namespace Siro\Core\Webhook;

use InvalidArgumentException;
use RuntimeException;
use Siro\Core\Http;
use Siro\Core\QueueInterface;

/**
 * Queue job that delivers one signed webhook event (G5, v1.5.0).
 *
 * The job itself only signs + POSTs. Retry scheduling and dead-lettering
 * are Queue's job (`Queue::work()`): any exception thrown here — transport
 * failure or a non-2xx response — is retried with exponential backoff and
 * moved to `failed_jobs` after `max_attempts`. The job must therefore
 * never swallow a delivery failure.
 *
 * Data shape (JSON-serializable, as Queue requires):
 *   url     string  Destination endpoint (http/https, required)
 *   event   string  Event name, sent as `X-Siro-Event`
 *   payload array   Event payload (JSON-encoded before signing)
 *   secret  string  HMAC secret for signing (required)
 *   timeout int     HTTP timeout in seconds (default 10)
 *
 * @package Siro\Core\Webhook
 */
final class WebhookDeliveryJob implements QueueInterface
{
    public const int DEFAULT_TIMEOUT = 10;

    /** First retry delay in seconds; doubles per attempt, capped (see backoffSeconds()). */
    public const int BASE_BACKOFF_SECONDS = 5;

    /** Maximum retry delay in seconds. */
    public const int MAX_BACKOFF_SECONDS = 300;

    /** @param array<string, mixed> $data */
    public function handle(array $data = []): void
    {
        $url = isset($data['url']) && is_string($data['url']) ? $data['url'] : '';
        $event = isset($data['event']) && is_string($data['event']) ? $data['event'] : '';
        $secret = isset($data['secret']) && is_string($data['secret']) ? $data['secret'] : '';
        $timeout = isset($data['timeout']) && is_numeric($data['timeout']) ? (int) $data['timeout'] : self::DEFAULT_TIMEOUT;
        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

        if (!self::isHttpUrl($url)) {
            throw new InvalidArgumentException("WebhookDeliveryJob requires an http(s) url, '{$url}' given.");
        }
        if ($secret === '') {
            throw new InvalidArgumentException('WebhookDeliveryJob requires a non-empty secret.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('WebhookDeliveryJob could not JSON-encode the payload.');
        }

        $signature = WebhookSigner::sign($json, $secret);

        Http::timeout(max(1, $timeout));
        $response = Http::post($url, $json, [
            'Content-Type' => 'application/json',
            WebhookSigner::HEADER => $signature,
            'X-Siro-Event' => $event,
        ]);

        if (!$response->ok()) {
            throw new RuntimeException(
                "Webhook delivery to {$url} failed: HTTP {$response->status()} for event '{$event}'."
            );
        }
    }

    /**
     * Retry delay in seconds for a given attempt number (1-based).
     * Mirrors the schedule Queue applies between retries: 5, 10, 20, 40,
     * 80, 160, then capped at 300. Attempt < 1 is treated as attempt 1.
     */
    public static function backoffSeconds(int $attempt): int
    {
        $attempt = max(1, $attempt);
        return (int) min(
            self::BASE_BACKOFF_SECONDS * (2 ** ($attempt - 1)),
            self::MAX_BACKOFF_SECONDS
        );
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
