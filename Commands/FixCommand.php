<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Watch code changes and auto-replay last API test.
 *
 * Monitors app/ and routes/ directories for file changes.
 * When a change is detected, automatically replays the
 * last api:test request and shows pass/fail status.
 *
 * @package Siro\Core\Commands
 */
final class FixCommand
{
    use CommandSupport;

    private string $basePath;
    private array $lastStatus = [];
    private int $watchPid = 0;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        // --last or <trace_id> support
        $targetTrace = null;
        foreach ($args as $arg) {
            if ($arg === '--last') {
                $targetTrace = '__last__';
            } elseif (!str_starts_with($arg, '--') && $arg !== '') {
                $targetTrace = $arg;
            }
        }

        // If trace_id provided, just replay it once
        if ($targetTrace !== null && $targetTrace !== '__last__') {
            $traceFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces' . DIRECTORY_SEPARATOR . $targetTrace . '.json';
            if (!file_exists($traceFile)) {
                $this->write('  ⚠ Trace not found: ' . $targetTrace);
                return 1;
            }
            $data = json_decode((string) file_get_contents($traceFile), true);
            if (!is_array($data)) {
                $this->write('  ⚠ Invalid trace file.');
                return 1;
            }
            $method = $data['method'] ?? 'GET';
            $host = $data['host'] ?? 'localhost:8080';
            $path = $data['path'] ?? '/';
            $body = $data['request_body'] ?? '';

            $ch = curl_init('http://' . $host . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_POSTFIELDS => $body,
            ] + ($body ? [CURLOPT_HTTPHEADER => ['Content-Type: application/json']] : []));
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->write('');
            $this->write('  🔄 Replayed: ' . $method . ' ' . $path);
            $this->write('  Status: ' . $status . ' ' . ($status >= 200 && $status < 300 ? '✅' : '❌'));
            return $status >= 200 && $status < 300 ? 0 : 1;
        }

        $dirs = [
            $this->basePath . DIRECTORY_SEPARATOR . 'app',
            $this->basePath . DIRECTORY_SEPARATOR . 'routes',
        ];

        $this->write('');
        $this->write('  ⚡ Siro Fix — watching for changes...');
        $this->write('  ' . str_repeat('-', 40));
        $this->write('  Watching: app/, routes/');
        $this->write('  Auto-replays last API test request on change');
        $this->write('  Press Ctrl+C to stop.');
        $this->write('');

        // Get the last api:test command from history
        $lastTest = $this->getLastApiTest();

        if ($lastTest === null) {
            $this->write('  ⚠ No previous api:test found. Run one first:');
            $this->write('    php siro api:test GET /api/users');
            return 1;
        }

        $this->write('  Last test: ' . $lastTest);
        $this->write('  Watching...');

        $lastMtime = $this->getMaxMtime($dirs);

        while (true) {
            sleep(1);
            $currentMtime = $this->getMaxMtime($dirs);
            if ($currentMtime > $lastMtime) {
                $lastMtime = $currentMtime;
                $traceId = $this->getLastTraceId();
                $this->write('');
                $this->write('  🔄 Code changed → replaying ' . ($traceId ?? 'last request') . '...');
                $output = shell_exec($lastTest . ' 2>&1');
                if ($output !== null) {
                    $lines = explode("\n", $output);
                    $statusLine = '';
                    foreach ($lines as $line) {
                        if (str_contains($line, 'Status:')) {
                            $statusLine = trim($line);
                            if (str_contains($statusLine, '200') || str_contains($statusLine, '201')) {
                                $this->write('  ✅ ' . $statusLine . ' — FIX SUCCESSFUL');
                            } elseif (str_contains($statusLine, '422') || str_contains($statusLine, '400') || str_contains($statusLine, '401')) {
                                $this->write('  ❌ ' . $statusLine . ' — still failing');
                                // Show the error
                                foreach ($lines as $l) {
                                    if (str_contains($l, '❌') || str_contains($l, 'Validation failed') || str_contains($l, 'error')) {
                                        $this->write('     ' . trim($l));
                                    }
                                }
                            } else {
                                $this->write('  ' . $statusLine);
                            }
                            break;
                        }
                    }
                }
                $this->write('  Watching...');
            }
        }
    }

    private function getMaxMtime(array $dirs): int
    {
        $max = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $mtime = $file->getMTime();
                    if ($mtime > $max) $max = $mtime;
                }
            }
        }
        return $max;
    }

    private function getLastTraceId(): ?string
    {
        $traceDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        if (!is_dir($traceDir)) return null;
        $files = glob($traceDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if ($files === []) return null;
        rsort($files);
        return basename($files[0], '.json');
    }

    private function getLastApiTest(): ?string
    {
        $historyFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'api-test-history.json';
        if (file_exists($historyFile)) {
            $history = json_decode((string) file_get_contents($historyFile), true);
            if (is_array($history) && $history !== []) {
                $last = end($history);
                if (isset($last['command'])) {
                    return $last['command'];
                }
            }
        }
        return null;
    }
}
