<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Enable maintenance mode.
 *
 * Creates storage/framework/down marker file with
 * customizable message, retry-after duration, and
 * IP allowlist for authorized access during maintenance.
 *
 * @package Siro\Core\Commands
 */
class DownCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public string $name = 'down';
    public string $description = 'Enable maintenance mode';

    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $message = 'Upgrading... please wait.';
        $retry = 60;
        $allow = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--message=')) {
                $message = substr($arg, 10);
            } elseif (str_starts_with($arg, '--retry=')) {
                $retry = max(0, (int) substr($arg, 8));
            } elseif (str_starts_with($arg, '--allow=')) {
                $allow = explode(',', substr($arg, 8));
            }
        }

        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $data = [
            'time' => time(),
            'message' => $message,
            'retry' => $retry,
            'allow' => $allow,
        ];

        file_put_contents($dir . DIRECTORY_SEPARATOR . 'down', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->write('Application is now in maintenance mode.');
        if ($message !== '') {
            $this->write("  Message: {$message}");
        }
        return 0;
    }
}
