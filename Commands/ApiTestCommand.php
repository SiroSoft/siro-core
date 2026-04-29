<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class ApiTestCommand
{
    use CommandSupport;

    private string $authFile;
    private string $historyFile;

    public function __construct(private readonly string $basePath)
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        $this->authFile = $dir . DIRECTORY_SEPARATOR . 'api-test-auth.json';
        $this->historyFile = $dir . DIRECTORY_SEPARATOR . 'api-test-history.json';
    }

    public function run(array $args): int
    {
        if ($args === []) {
            $this->write('Usage: php siro api:test <method> <path> [field:value...] [options]');
            $this->write('Options:');
            $this->write('  --json              Send as JSON (default)');
            $this->write('  --form              Send as form-urlencoded');
            $this->write('  --header="X: v"     Custom header');
            $this->write('  --as=<role>         Auth as role (admin, user)');
            $this->write('  --port=<port>       Server port (default 8000)');
            $this->write('  --history           View request history');
            $this->write('  --history=N          Show last N requests');
            $this->write('  --history-clear     Clear history');
            $this->write('');
            $this->write('Examples:');
            $this->write('  php siro api:test POST /auth/login email=admin@test.com password=123456');
            $this->write('  php siro api:test GET /users --as=admin');
            $this->write('  php siro api:test POST /users name=John email=john@test.com --as=admin');
            $this->write('  php siro api:test --history');
            return 0;
        }

        if (in_array('--history', $args, true) || in_array('--history-clear', $args, true)) {
            if (in_array('--history-clear', $args, true)) {
                $this->clearHistory();
                return 0;
            }
            $limit = 10;
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--history=')) {
                    $limit = max(1, (int) substr($arg, 10));
                    break;
                }
            }
            return $this->showHistory($limit);
        }

        $method = strtoupper($args[0] ?? '');
        $path = $args[1] ?? '';

        if ($method === '' || $path === '') {
            $this->write('Error: Method and path are required.');
            $this->write('Usage: php siro api:test GET /api/users');
            return 1;
        }

        $fields = [];
        $headers = [];
        $as = null;
        $port = 8000;
        $contentType = 'json';

        for ($i = 2; $i < count($args); $i++) {
            $arg = $args[$i];
            if ($arg === '--json') {
                $contentType = 'json';
            } elseif ($arg === '--form') {
                $contentType = 'form';
            } elseif (str_starts_with($arg, '--header=')) {
                $headers[] = substr($arg, 9);
            } elseif (str_starts_with($arg, '--as=')) {
                $as = substr($arg, 5);
            } elseif (str_starts_with($arg, '--port=')) {
                $port = max(1, (int) substr($arg, 7));
            } elseif (str_contains($arg, '=')) {
                $parts = explode('=', $arg, 2);
                $fields[$parts[0]] = $parts[1];
            }
        }

        if ($as !== null) {
            $token = $this->getToken($as);
            if ($token === null) {
                $this->write("Warning: No saved token for role '{$as}'. Send request without auth.");
                $this->write("  Login first: php siro api:test POST /auth/login email={$as}@test.com password=... --as={$as}");
            } else {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }

        return $this->sendRequest($method, $path, $fields, $headers, $contentType, $port, $as);
    }

    private function sendRequest(
        string $method,
        string $path,
        array $fields,
        array $headers,
        string $contentType,
        int $port,
        ?string $as
    ): int {
        $url = "http://127.0.0.1:{$port}{$path}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $curlHeaders = ['Accept: application/json'];

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            if ($contentType === 'form') {
                $curlHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
            } else {
                $curlHeaders[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields, JSON_UNESCAPED_UNICODE));
            }
        }

        foreach ($headers as $h) {
            $curlHeaders[] = $h;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $start = microtime(true);
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        $duration = (microtime(true) - $start) * 1000;
        curl_close($ch);

        if ($error !== '') {
            $this->write("Error: {$error}");
            $this->write("Make sure the server is running: php siro serve --port={$port}");
            return 1;
        }

        $headerSize = (int) $info['header_size'];
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        $httpCode = (int) $info['http_code'];
        $bodySize = strlen($responseBody);

        $this->write('');
        $this->write("  \033[1;33m{$method} {$path}\033[0m");

        $color = $httpCode < 300 ? '32' : ($httpCode < 400 ? '33' : '31');
        $this->write("  \033[{$color}mStatus: {$httpCode} OK\033[0m");
        $this->write("  Time:   " . number_format($duration, 1) . "ms");
        $this->write("  Size:   " . $this->formatBytes($bodySize));

        $this->write('');
        $this->write("  \033[1;90mResponse Headers:\033[0m");
        $lines = explode("\n", trim($responseHeaders));
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $this->write("    {$trimmed}");
            }
        }

        $this->write('');
        $this->write("  \033[1;90mBody:\033[0m");

        if ($responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $this->write(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else {
                $this->write('    ' . $responseBody);
            }
        } else {
            $this->write("    (empty)");
        }

        $this->write('');

        if ($as !== null && $httpCode < 300) {
            $this->autoSaveToken($responseBody, $as, $method, $path);
        }

        $this->saveHistory($method, $path, $fields, $headers, $httpCode, $duration, $bodySize, $as);

        return $httpCode < 400 ? 0 : 1;
    }

    private function autoSaveToken(string $body, string $role, string $method, string $path): void
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return;
        }

        $token = $decoded['data']['token'] ?? $decoded['token'] ?? null;
        if ($token === null || !is_string($token) || strlen($token) < 10) {
            return;
        }

        $tokens = $this->loadTokens();
        $tokens[$role] = $token;
        file_put_contents($this->authFile, json_encode($tokens, JSON_PRETTY_PRINT));

        $this->write("  \033[32m✓ Token for '{$role}' saved.\033[0m");
    }

    private function getToken(string $role): ?string
    {
        $tokens = $this->loadTokens();
        return $tokens[$role] ?? null;
    }

    private function loadTokens(): array
    {
        if (!is_file($this->authFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->authFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveHistory(
        string $method,
        string $path,
        array $fields,
        array $headers,
        int $httpCode,
        float $duration,
        int $bodySize,
        ?string $as
    ): void {
        $history = $this->loadHistory();
        $history[] = [
            'time' => date('Y-m-d H:i:s'),
            'method' => $method,
            'path' => $path,
            'fields' => $fields,
            'headers' => $headers,
            'status' => $httpCode,
            'duration_ms' => round($duration, 1),
            'size_bytes' => $bodySize,
            'as' => $as,
        ];

        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }

        file_put_contents($this->historyFile, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function loadHistory(): array
    {
        if (!is_file($this->historyFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->historyFile), true);
        return is_array($data) ? $data : [];
    }

    private function showHistory(int $limit): int
    {
        $history = $this->loadHistory();

        if ($history === []) {
            $this->write('No request history found.');
            $this->write('Make a request first: php siro api:test GET /api/users');
            return 0;
        }

        $history = array_reverse($history);
        $history = array_slice($history, 0, $limit);

        $headers = ['#', 'Time', 'Method', 'Path', 'Status', 'Time', 'Size', 'As'];
        $rows = [];

        foreach ($history as $i => $entry) {
            $status = (string) $entry['status'];
            $statusStr = $entry['status'] < 300 ? $status : "\033[31m{$status}\033[0m";
            $rows[] = [
                (string) ($i + 1),
                $entry['time'] ?? '?',
                $entry['method'],
                $entry['path'],
                $statusStr,
                ($entry['duration_ms'] ?? '?') . 'ms',
                $this->formatBytes($entry['size_bytes'] ?? 0),
                $entry['as'] ?? '-',
            ];
        }

        $this->table($headers, $rows);
        $this->write('');
        $this->write('Total: ' . count($history) . ' requests');
        $this->write('Use --history=N to show more, --history-clear to clear');

        return 0;
    }

    private function clearHistory(): int
    {
        if (is_file($this->historyFile)) {
            unlink($this->historyFile);
        }
        $this->write('Request history cleared.');
        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . 'KB';
        }
        return number_format($bytes / (1024 * 1024), 1) . 'MB';
    }
}
