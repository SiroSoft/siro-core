<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogReplayCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $traceId = trim((string) ($args[0] ?? ''));
        $format = 'curl';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
            }
        }

        if ($traceId === '') {
            $this->write('Usage: php siro log:replay <trace_id>');
            $this->write('       php siro log:replay <trace_id> --format=httpie');
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

        // Add auth header if present
        $auth = $data['auth_header'] ?? '';
        $ct = $data['content_type'] ?? '';

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
