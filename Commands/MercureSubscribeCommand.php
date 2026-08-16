<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Env;

final class MercureSubscribeCommand implements CommandInterface
{
    use CommandSupport;

    /** @var string Base path (used by Console when constructing commands) */
    private readonly string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $topic = $args[0] ?? '';
        if ($topic === '') {
            $this->write('Usage: php siro mercure:subscribe <topic>');
            $this->write('  Base path: ' . $this->basePath);
            return 1;
        }

        $hubUrl = Env::get('MERCURE_HUB_URL', 'http://localhost:3001/.well-known/mercure');
        $jwt = Env::get('MERCURE_SUBSCRIBER_JWT', '');
        if ($jwt === '') {
            $this->write('MERCURE_SUBSCRIBER_JWT is not set.');
            return 1;
        }

        $url = $hubUrl . '?topic=' . urlencode($topic);
        $this->write("Subscribing to: {$topic}");
        $this->write("Hub URL: {$hubUrl}");
        $this->write('Waiting for events... (Ctrl+C to stop)');
        $this->write('');

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: text/event-stream',
                    'Authorization: Bearer ' . $jwt,
                ],
                'timeout' => 0,
            ],
        ]);

        $stream = fopen($url, 'r', false, $ctx);
        if (!is_resource($stream)) {
            $this->write('Failed to connect to Mercure hub.');
            return 1;
        }

        stream_set_blocking($stream, true);

        $buffer = '';
        while (!feof($stream)) {
            $chunk = fgets($stream);
            if ($chunk === false) {
                break;
            }

            $buffer .= $chunk;

            if (trim($chunk) === '') {
                $this->processEvent($buffer);
                $buffer = '';
            }
        }

        fclose($stream);
        return 0;
    }

    private function processEvent(string $buffer): void
    {
        $lines = explode("\n", trim($buffer));
        $event = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data: ')) {
                $event['data'] = substr($line, 6);
            } elseif (str_starts_with($line, 'id: ')) {
                $event['id'] = substr($line, 4);
            } elseif (str_starts_with($line, 'type: ')) {
                $event['type'] = substr($line, 6);
            }
        }

        $timestamp = date('Y-m-d H:i:s');
        if (isset($event['type'])) {
            $this->write("[{$timestamp}] Type: {$event['type']}");
        }
        if (isset($event['data'])) {
            $decoded = json_decode($event['data'], true);
            if (is_array($decoded)) {
                $this->write("[{$timestamp}] " . json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->write("[{$timestamp}] {$event['data']}");
            }
        }
        $this->write('');
    }
}
