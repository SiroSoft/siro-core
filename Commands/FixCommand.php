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
final class FixCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    private string $basePath;

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
            $tracesDir = $this->getTracesDir($this->basePath);
            $traceFile = $this->findTraceById($tracesDir, $targetTrace);
            if ($traceFile === null) {
                $this->write('  ⚠ Trace not found: ' . $targetTrace);
                return 1;
            }
            $data = json_decode((string) file_get_contents($traceFile), true);
            if (!is_array($data)) {
                $this->write('  ⚠ Invalid trace file.');
                return 1;
            }
            $method = $this->safeStr($data['method'] ?? 'GET');
            $host = $this->safeStr($data['host'] ?? 'localhost:8080');
            $path = $this->safeStr($data['path'] ?? '/');
            // Enterprise hardening: reject tampered trace targets (SSRF/URL injection).
            if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) || preg_match('/[\x00-\x1F\x7F\s]/', $path)) {
                $this->write('  ❌ Refusing to replay: invalid host/path in trace.');
                return 1;
            }
            $body = $this->safeStr($data['request_body'] ?? '');
            $auth = $this->safeStr($data['auth_header'] ?? '');
            $ct = $this->safeStr($data['content_type'] ?? '');
            /** @var array<string, string>|null $rawHeaders */
            $rawHeaders = $data['request_headers'] ?? null;
            /** @var array<string, string> $rawHeaders */
            $headers = $rawHeaders;

            $curlHeaders = ['Content-Type: ' . ($ct !== '' ? $ct : 'application/json')];
            if ($auth !== '') {
                $curlHeaders[] = 'Authorization: ' . $auth;
            }
            foreach ($headers as $k => $v) {
                $lk = strtolower((string) $k);
                if (in_array($lk, ['host', 'content-length', 'content-type', 'authorization'], true)) continue;
                $curlHeaders[] = (string) $k . ': ' . $v;
            }

            $ch = curl_init('http://' . $host . $path);
            $safeMethod = $method !== '' ? $method : 'GET';
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $safeMethod,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => $curlHeaders,
            ]);
            if ($body !== '' && $body !== '[]' && $body !== '{}') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->write('');
            $this->write('  🔄 Fix replay: ' . $method . ' ' . $path);
            $statusColor = $status >= 500 ? '❌' : ($status >= 400 ? '⚠️' : '✅');
            $this->write('  Status: ' . $status . ' ' . $statusColor);
            if (is_string($response) && $response !== '') {
                $decoded = json_decode($response, true);
                if (is_array($decoded)) {
                    $this->write('  Response: ' . (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    $this->write('  Response: ' . substr($response, 0, 200));
                }
            }
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

        // @phpstan-ignore-next-line while.alwaysTrue
        while (true) {
            sleep(1);
            $currentMtime = $this->getMaxMtime($dirs);
            if ($currentMtime > $lastMtime) {
                $lastMtime = $currentMtime;
                $traceId = $this->getLastTraceId();
                $this->write('');
                $this->write('  🔄 Code changed → replaying ' . ($traceId ?? 'last request') . '...');
                // Run the last api:test through the Siro CLI
                $cmd = 'php ' . escapeshellarg($this->basePath . DIRECTORY_SEPARATOR . 'siro') . ' ' . $lastTest . ' 2>&1';
                $output = shell_exec($cmd);
                if ($output !== null) {
                    $lines = explode("\n", (string) $output);
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

    /**
     * @param array<int, string> $dirs
     */
    private function getMaxMtime(array $dirs): int
    {
        $max = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $mtime = $file->getMTime();
                    if ($mtime > $max) $max = $mtime;
                }
            }
        }
        return $max;
    }

    private function getLastTraceId(): ?string
    {
        $tracesDir = $this->getTracesDir($this->basePath);
        $rawFiles = $this->findTraceFiles($tracesDir);
        if ($rawFiles === []) return null;
        usort($rawFiles, fn(string $a, string $b) => filemtime($b) <=> filemtime($a));
        return basename($rawFiles[0], '.json');
    }

    private function getLastApiTest(): ?string
    {
        $historyFile = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'api-test-history.json';
        if (file_exists($historyFile)) {
            $history = json_decode((string) file_get_contents($historyFile), true);
            if (is_array($history) && $history !== []) {
                $last = end($history);
                if (is_array($last)) {
                    // Command string stored verbatim (newer versions)
                    if (isset($last['command']) && is_string($last['command']) && $last['command'] !== '') {
                        return $last['command'];
                    }
                    // Reconstruct from method + path + fields (schema written by api:test)
                    $method = is_string($last['method'] ?? null) ? strtoupper($last['method']) : '';
                    $path = is_string($last['path'] ?? null) ? $last['path'] : '';
                    if ($method !== '' && $path !== '') {
                        $cmd = "api:test {$method} {$path}";
                        $fields = $last['fields'] ?? null;
                        if (is_array($fields) && $fields !== []) {
                            foreach ($fields as $k => $v) {
                                if (is_scalar($v)) {
                                    $cmd .= " {$k}=" . (string) $v;
                                }
                            }
                        }
                        return $cmd;
                    }
                }
            }
        }
        return null;
    }
}
