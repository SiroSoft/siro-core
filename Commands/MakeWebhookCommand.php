<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Scaffold a webhook listener class (G5, v1.5.0).
 *
 *   php siro make:webhook OrderCreated
 *
 * Generates `app/Webhooks/OrderCreatedWebhook.php` with a
 * `handle(string $event, array $payload = []): void` method. The listener
 * is a plain class: verify the signature at your endpoint with
 * `WebhookSigner::verify()` and call the listener from there.
 */
final class MakeWebhookCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Webhook name is required. Example: php siro make:webhook OrderCreated');
            return 1;
        }

        $class = $this->studly($name);
        $suffix = 'Webhook';
        if (!str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        $path = $this
            ->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Webhooks' . DIRECTORY_SEPARATOR . $class .
            '.php';

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if (is_file($path)) {
            $this->write('Already exists: app/Webhooks/' . $class . '.php');
            return 0;
        }

        file_put_contents($path, $this->template($class));
        $this->write('Generated: app/Webhooks/' . $class . '.php');
        $this->write('');
        $this->write('  Register an endpoint in config/webhooks.php:');
        $this->write('    [\'url\' => \'https://example.com/webhooks/orders\', \'events\' => [\'order.created\']],');
        $this
            ->write('  Then dispatch: (new \Siro\Core\Webhook\WebhookDispatcher())->dispatch(\'order.created\', ' .
            '$payload);');

        return 0;
    }

    private function template(string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Webhooks;

/**
 * Handle a verified webhook event.
 *
 * Verify the signature at your endpoint first:
 *   \$ok = \\Siro\\Core\\Webhook\\WebhookSigner::verify(\$rawBody, \$signatureHeader, \$secret);
 */
final class {$class}
{
    /**
     * @param array<string, mixed> \$payload Event payload
     */
    public function handle(string \$event, array \$payload = []): void
    {
        // Process the event
        // \$orderId = \$payload['order_id'] ?? null;
    }
}
PHP;
    }
}
