<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class TestRegressionCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    private const RESET = "\033[0m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const CYAN = "\033[36m";
    private const BOLD = "\033[1m";
    private const GRAY = "\033[90m";

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $limit = 50;
        $statusFilter = null;
        $failOnly = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--limit=')) {
                $limit = max(1, (int) substr($arg, 7));
            } elseif (str_starts_with($arg, '--status=')) {
                $statusFilter = (int) substr($arg, 9);
            } elseif ($arg === '--fail') {
                $failOnly = true;
            }
        }

        $tracesDir = $this->getTracesDir($this->basePath);
        $files = $this->findTraceFiles($tracesDir);
        if ($files === []) {
            $this->warn('No traces found.');
            return 1;
        }

        rsort($files);
        $files = array_slice($files, 0, $limit);

        $this->write('');
        $this->info('Replaying ' . count($files) . ' traces, comparing responses...');
        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);

        $total = 0;
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $changes = [];

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            $method = strtoupper($this->safeStr($data['method'] ?? 'GET'));
            $path = $this->safeStr($data['path'] ?? '/');
            $origStatus = is_numeric($data['status'] ?? null) ? (int) $data['status'] : 0;
            $traceId = $this->safeStr($data['trace_id'] ?? basename($file, '.json'));
            $rawHost = $this->safeStr($data['host'] ?? '');
            $body = $this->safeStr($data['request_body'] ?? '');
            $auth = $this->safeStr($data['auth_header'] ?? '');

            $rawHeaders = $data['request_headers'] ?? [];
            $headers = is_array($rawHeaders) ? $rawHeaders : [];

            if ($statusFilter !== null && $origStatus !== $statusFilter) {
                continue;
            }

            $host = $rawHost !== '' ? $rawHost : 'localhost:8080';
            $url = 'http://' . $host . $path;
            $total++;

            $curlHeaders = [];
            foreach ($headers as $k => $v) {
                $lk = strtolower((string) $k);
                if (in_array($lk, ['host', 'content-length'], true)) {
                    continue;
                }
                $vStr = is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
                $curlHeaders[] = (string) $k . ': ' . $vStr;
            }
            if ($auth !== '') {
                $curlHeaders[] = 'Authorization: ' . $auth;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method !== '' ? $method : null,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => $curlHeaders,
            ]);
            if ($body !== '' && $body !== '[]' && $body !== '{}') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $newStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $issues = [];

            if ($curlError !== '') {
                $issues[] = 'connection_error: ' . $curlError;
                $errors++;
            }

            if ($curlError === '') {
                if ($newStatus !== $origStatus) {
                    $issues[] = 'status_changed: ' . $origStatus . ' -> ' . $newStatus;
                }
            if (is_string($response) && $response !== '') {
                $newBody = json_decode($response, true);
                $origBody = json_decode($this->safeStr($data['response_body'] ?? '{}'), true);
                    if (is_array($newBody) && is_array($origBody)) {
                        $origSuccess = $origBody['success'] ?? null;
                        $newSuccess = $newBody['success'] ?? null;
                        if ($origSuccess !== null && $newSuccess !== null && $origSuccess !== $newSuccess) {
                            $issues[] = 'success_changed: ' . ($origSuccess ? 'true' : 'false') . ' -> ' . ($newSuccess ? 'true' : 'false');
                        }
                        foreach ($origBody as $key => $val) {
                            if (!array_key_exists($key, $newBody)) {
                                $issues[] = 'missing_key: ' . $key;
                            }
                        }
                    }
                }
            }

            if ($issues === []) {
                $passed++;
            } else {
                $failed++;
                if ($failOnly || $curlError === '') {
                    $changes[] = [
                        'trace_id' => $traceId,
                        'method' => $method,
                        'path' => $path,
                        'issues' => $issues,
                    ];
                }
            }
        }

        $this->write('');
        $this->write('  ' . self::BOLD . 'Results' . self::RESET);
        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);
        $this->write('  Total:   ' . $total);
        $this->write('  ' . self::GREEN . 'Passed:  ' . $passed . self::RESET);
        if ($failed > 0) {
            $this->write('  ' . self::RED . 'Failed:  ' . $failed . self::RESET);
        }
        if ($errors > 0) {
            $this->write('  ' . self::YELLOW . 'Errors:  ' . $errors . self::RESET);
        }

        if ($changes !== []) {
            $this->write('');
            $this->write('  ' . self::BOLD . 'Failed Traces' . self::RESET);
            $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);
            foreach ($changes as $c) {
                $this->write('  ' . self::CYAN . $c['method'] . ' ' . $c['path'] . self::RESET);
                $this->write('  ' . self::GRAY . '    Trace: ' . $c['trace_id'] . self::RESET);
                foreach ($c['issues'] as $issue) {
                    $color = str_contains($issue, 'connection_error') ? self::YELLOW : self::RED;
                    $this->write('    ' . $color . chr(10007) . ' ' . $issue . self::RESET);
                }
                $this->write('    ' . self::CYAN . 'Replay: php siro replay ' . $c['trace_id'] . ' --force' . self::RESET);
            }
        }

        $this->write('');
        $this->write('  ' . self::GRAY . str_repeat('─', 56) . self::RESET);

        if ($failed === 0) {
            $this->success('All ' . $total . ' traces match - no regressions detected.');
            return 0;
        }
        $this->warn($failed . '/' . $total . ' traces have changes.');
        return 1;
    }
}
