<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogReplayCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $traceId = trim((string) ($args[0] ?? ''));
        $format = 'curl';
        $force = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
            } elseif ($arg === '--force') {
                $force = true;
            } elseif ($arg === '--safe') {
                $force = false;
            }
        }

        if ($traceId === '') {
            $this->write('Usage: php siro log:replay <trace_id> [options]');
            $this->write('');
            $this->write('Options:');
            $this->write('  --format=curl     Output as curl (default)');
            $this->write('  --format=httpie   Output as httpie');
            $this->write('  --force           Skip safe-mode warning (dangerous)');
            $this->write('  --safe            Safe mode: warn on non-GET (default)');
            $this->write('');
            $this->write('Examples:');
            $this->write('  php siro log:replay siro_a1b2');
            $this->write('  php siro log:replay siro_a1b2 --force');
            return 1;
        }

        $traceFile = $this->basePath
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'traces'
            . DIRECTORY_SEPARATOR . $traceId . '.json';

        if (!is_file($traceFile)) {
            $this->write('Trace not found: ' . $traceId);
            return 1;
        }

        $data = json_decode((string) file_get_contents($traceFile), true);
        if (!is_array($data)) {
            $this->write('Invalid trace file.');
            return 1;
        }

        $method = strtoupper($data['method'] ?? 'GET');
        $host = $data['host'] ?? 'localhost:8080';
        $path = $data['path'] ?? '/';
        $url = 'http://' . $host . $path;
        $headers = $data['request_headers'] ?? [];
        $body = $data['request_body'] ?? '';
        $auth = $data['auth_header'] ?? '';
        $ct = $data['content_type'] ?? '';

        // ─── Safe mode: warning for non-GET methods ───
        if ($method !== 'GET' && !$force) {
            $this->write('');
            $this->write("  \033[1;31m⚠  DANGER: Replaying {$method} {$path}\033[0m");
            $this->write('  This request has side effects (not a read-only GET).');
            $this->write('  Replaying it may:');
            $this->write('    • Charge user again (payment)');
            $this->write('    • Create duplicate records (insert)');
            $this->write('    • Modify data unexpectedly (update)');
            $this->write('    • Delete data permanently (delete)');
            $this->write('');
            $this->write('  To confirm, re-run with: --force');
            $this->write('  To see without executing, use: php siro log:export ' . $traceId . ' --postman');
            $this->write('');

            $answer = $this->ask('  \033[1;33mAre you sure you want to replay this? (yes/no): \033[0m');
            if (strtolower(trim($answer)) !== 'yes') {
                $this->write('  Replay cancelled.');
                return 1;
            }
            $this->write('');
        }

        if ($format === 'httpie') {
            return $this->outputHttpie($method, $url, $headers, $body, $auth, $ct);
        }

        return $this->outputCurl($method, $url, $headers, $body, $auth, $ct);
    }

    private function outputCurl(string $method, string $url, array $headers, string $body, string $auth, string $ct): int
    {
        $parts = ['curl', '-X', $method, escapeshellarg($url)];

        if ($auth !== '') {
            $parts[] = '-H';
            $parts[] = escapeshellarg('Authorization: ' . $auth);
        }

        if ($ct !== '') {
            $parts[] = '-H';
            $parts[] = escapeshellarg('Content-Type: ' . $ct);
        } else {
            $parts[] = '-H';
            $parts[] = escapeshellarg('Content-Type: application/json');
        }

        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization' || strtolower($key) === 'content-type') {
                continue;
            }
            $parts[] = '-H';
            $parts[] = escapeshellarg($key . ': ' . $value);
        }

        if ($body !== '' && $body !== '[]' && $body !== '{}') {
            $parts[] = '-d';
            $parts[] = escapeshellarg($body);
        }

        $this->write(implode(" \\\n  ", $parts));
        return 0;
    }

    private function outputHttpie(string $method, string $url, array $headers, string $body, string $auth, string $ct): int
    {
        $parts = ['http', $method, $url];

        if ($auth !== '') {
            $parts[] = escapeshellarg('Authorization:' . $auth);
        }

        if ($body !== '' && $body !== '[]' && $body !== '{}') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $parts[] = escapeshellarg($key . '=' . (is_string($value) ? $value : json_encode($value)));
                }
            }
        }

        $this->write(implode(" \\\n  ", $parts));
        return 0;
    }
}
