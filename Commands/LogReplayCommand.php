<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Replay a captured request trace.
 *
 * Generates curl/httpie commands or executes the request.
 * Supports --edit (interactive body edit), --diff (before/after
 * comparison), --dry-run (safe preview), --set (field override),
 * and --force for production override with APP_ENV=local.
 *
 * @package Siro\Core\Commands
 */
final class LogReplayCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $traceId = trim((string) ($args[0] ?? ''));
        $format = 'curl';
        $force = false;
        $editMode = false;
        $diffMode = false;
        $dryRun = false;
        $overrides = [];

        // Production safety: detect environment
        $env = strtolower((string) getenv('APP_ENV'));
        if ($env === '') {
            $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
            if (file_exists($envFile)) {
                $content = file_get_contents($envFile);
                if (preg_match('/^APP_ENV\s*=\s*(\w+)/m', (string) $content, $m)) {
                    $env = strtolower($m[1]);
                }
            }
        }
        $isProduction = in_array($env, ['production', 'prod', 'staging'], true);

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
            } elseif ($arg === '--force') {
                $force = true;
            } elseif ($arg === '--dry-run') {
                $dryRun = true;
            } elseif ($arg === '--safe') {
                $force = false;
            } elseif ($arg === '--edit') {
                $editMode = true;
            } elseif ($arg === '--diff') {
                $diffMode = true;
            } elseif (str_starts_with($arg, '--set=')) {
                $setArg = substr($arg, 6);
                // Support --set body.field=value (strip 'body.' prefix)
                if (str_starts_with($setArg, 'body.')) {
                    $setArg = substr($setArg, 5);
                }
                $parts = explode('=', $setArg, 2);
                if (isset($parts[1])) {
                    $overrides[$parts[0]] = $parts[1];
                }
            } elseif (str_starts_with($arg, '--seed')) {
                $overrides['_seed'] = '1';
            }
        }

        if ($traceId === '') {
            $this->write('Usage: php siro log:replay <trace_id> [options]');
            $this->write('');
            $this->write('Options:');
            $this->write('  --format=curl     Output as curl (default)');
            $this->write('  --format=httpie   Output as httpie');
            $this->write('  --force           Execute replay (required for non-GET)');
            $this->write('  --safe            Safe mode: warn on non-GET (default)');
            $this->write('  --set key=value   Override request field');
            $this->write('  --seed            Seed database from request data');
            $this->write('  --edit            Interactive edit request before replay');
            $this->write('  --diff            Compare before/after responses');
            $this->write('  --https           Use https:// instead of http://');
            $this->write('  --http            Force http:// (default)');
            $this->write('  --insecure        Skip SSL verification (self-signed certs)');
            $this->write('');
            $this->write('Examples:');
            $this->write('  php siro log:replay siro_a1b2');
            $this->write('  php siro log:replay siro_a1b2 --force');
            $this->write('  php siro log:replay siro_a1b2 --force --https');
            $this->write('  php siro log:replay siro_a1b2 --set user_id=1');
            $this->write('  php siro log:replay siro_a1b2 --edit');
            $this->write('  php siro log:replay siro_a1b2 --diff');
            return 1;
        }

        $tracesDir = $this->getTracesDir($this->basePath);
        $traceFile = $this->findTraceById($tracesDir, $traceId);

        if ($traceFile === null) {
            $this->write('Trace not found: ' . $traceId);
            return 1;
        }

        $data = json_decode((string) file_get_contents($traceFile), true);
        if (!is_array($data)) {
            $this->write('Invalid trace file.');
            return 1;
        }
        /** @var array<string, mixed> $data */

        $method = strtoupper($this->safeStr($data['method'] ?? 'GET'));
        $host = $this->safeStr($data['host'] ?? '');
        if ($host === '') {
            $host = 'localhost:8080';
        }
        $path = $this->safeStr($data['path'] ?? '/');

        // Detect HTTPS and insecure flags
        $useHttps = false;
        $insecure = false;
        foreach ($args as $arg) {
            if ($arg === '--https')    { $useHttps = true; }
            if ($arg === '--http')     { $useHttps = false; }
            if ($arg === '--insecure') { $insecure = true; }
        }
        $scheme = $useHttps ? 'https' : 'http';
        $url = $scheme . '://' . $host . $path;

        /** @var array<string, string>|null $rawHeaders */
        $rawHeaders = $data['request_headers'] ?? null;
        $headers = is_array($rawHeaders) ? $rawHeaders : [];
        $body = $this->safeStr($data['request_body'] ?? '');
        $auth = $this->safeStr($data['auth_header'] ?? '');
        $ct = $this->safeStr($data['content_type'] ?? '');

        // --edit: interactive edit
        if ($editMode) {
            $this->write('');
            $this->write('  ✏️  Edit request body:');
            $this->write('  ' . str_repeat('-', 40));
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $this->write('  ' . $this->safeStr($key) . ': \033[33m' . $this->safeStr($value) . '\033[0m');
                    $input = readline("  New value (Enter to keep): ");
                    if ($input !== '') {
                        $decoded[$key] = $input;
                    }
                }
            }
            $body = json_encode($decoded ?? [], JSON_UNESCAPED_UNICODE);
            $body = is_string($body) ? $body : '';
            $this->write('  ' . str_repeat('-', 40));
            $this->write('  Updated body: ' . $this->prettyPrint($body));
        }

        // Apply overrides
        $seedMode = false;
        foreach ($overrides as $key => $value) {
            if ($key === '_seed') {
                $seedMode = true;
                continue;
            }
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $decoded[$key] = $value;
                $encoded = json_encode($decoded);
                $body = is_string($encoded) ? $encoded : $body;
            }
        }

        if ($seedMode) {
            $seedData = json_decode($body, true);
            if (is_array($seedData)) {
                unset($seedData['id'], $seedData['created_at'], $seedData['updated_at']);
                $this->write('Seed command:');
                $this->write('  $db->table("' . $this->safeStr($data['table'] ?? 'table') . '")->insert(' . json_encode($seedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ');');
                $this->write('  Then run: php siro db:seed');
            }
            return 0;
        }

        // Dry run: preview only, no request sent
        if ($dryRun) {
            $this->write('');
            $this->write('  🔍 Dry run — no request sent');
            $this->write('  ' . str_repeat('-', 40));
            $this->write('  Method: ' . $method);
            $this->write('  URL:    ' . $url);
            $this->write('  Body:   ' . $body);
            $this->write('  Auth:   Bearer [token present]');
            $this->write('  ' . str_repeat('-', 40));
            $this->write('  To execute: php siro replay ' . $traceId);
            return 0;
        }

        // Production safety: by default auto dry-run, require explicit confirmation for exec
        if ($isProduction) {
            if (!$force && !$editMode && !$diffMode) {
                $this->write('');
                $this->write('  ⚠ Production environment detected — auto-switched to dry-run');
                $this->write('  ' . str_repeat('-', 40));
                $this->write('  Method: ' . $method);
                $this->write('  URL:    ' . $url);
                $this->write('  Body:   ' . $body);
                $this->write('  Auth:   Bearer [token present]');
                $this->write('  ' . str_repeat('-', 40));
                $this->write('  To replay: php siro replay ' . $traceId . ' --force  (with confirmation)');
                $this->write('');
                $this->outputCurl($method, $url, $body, $headers, $auth, $data);
                return 1;
            }

            // Dev explicitly passed --force, --edit, or --diff — require confirmation
            $mode = $diffMode ? 'diff' : ($editMode ? 'edit' : 'replay');
            $this->write('');
            $this->write('  ' . "\033[41m\033[97m ⚠ DANGER: Production environment! ⚠ \033[0m");
            $this->write('  You are about to ' . $mode . ' a ' . $method . ' request on PRODUCTION.');
            $this->write('  URL: ' . $url);
            if ($body !== '' && $body !== '{}') {
                $this->write('  Body: ' . $body);
            }
            $this->write('');
            $answer = $this->ask('  Are you sure? Type "yes" to continue: ');
            if (strtolower(trim($answer)) !== 'yes') {
                $this->write('  Cancelled.');
                return 1;
            }
            $this->write('');
        }

        // Check curl extension before any execution
        if (!function_exists('curl_init')) {
            $this->write('  ❌ PHP curl extension is not installed.');
            $this->write('  Install php-curl to use replay execution.');
            $this->write('  Alternative: use --dry-run to preview, or copy the curl command below.');
            $this->outputCurl($method, $url, $body, $headers, $auth, $data);
            return 1;
        }

        // --diff mode: execute and compare
        if ($diffMode) {
            $this->write('');
            $this->write('  🔄 Replaying with diff...');
            $this->write('  ' . str_repeat('=', 40));

            $beforeStatusVal = $data['status'] ?? 0;
            $beforeStatus = is_numeric($beforeStatusVal) ? (int) $beforeStatusVal : 0;
            $beforeBody = $this->safeStr($data['response_body'] ?? '');

            $this->auditReplay($traceId, $method, $path, 'diff');
            $result = $this->executeReplay($method, $url, $body, $headers, $auth, $ct, $data, $insecure);

            $curlError = $this->safeStr($result['error'] ?? '');
            if ($curlError !== '') {
                $this->write('  ❌ curl error: ' . $curlError);
                $this->write('  URL: ' . $url);
                return 1;
            }

            $this->write('');
            $this->write('  === BEFORE ===');
            $this->write('  Status: ' . $beforeStatus);
            $this->write('  Body:   ' . ($beforeBody !== '' ? $beforeBody : '(empty)'));
            $decodedBefore = json_decode((string) $beforeBody, true);
            if (is_array($decodedBefore)) {
                $beforeErrors = $decodedBefore['errors'] ?? [];
                if ($beforeErrors === [] && isset($decodedBefore['data']) && is_array($decodedBefore['data'])) {
                    $beforeErrors = $decodedBefore['data']['errors'] ?? [];
                }
                if (is_array($beforeErrors) && $beforeErrors !== []) {
                    foreach ($beforeErrors as $f => $m) {
                        $msgs = is_array($m) ? implode(', ', array_map(fn($v): string => $this->safeStr($v), (array) $m)) : $this->safeStr($m);
                        $this->write('  ❌ ' . $this->safeStr($f) . ': ' . $msgs);
                    }
                }
            }

            $this->write('');
            $this->write('  === AFTER ===');
            $statusAfter = is_numeric($result['status'] ?? null) ? (int) $result['status'] : 0;
            $statusColor = $statusAfter >= 500 ? 31 : ($statusAfter >= 400 ? 33 : ($statusAfter >= 200 && $statusAfter < 300 ? 32 : 0));
            $this->write('  Status: ' . ($statusColor ? "\033[{$statusColor}m{$statusAfter}\033[0m" : $statusAfter));
            $afterBody = $this->safeStr($result['body'] ?? '{}');
            $decodedAfter = json_decode($afterBody, true);
            if (is_array($decodedAfter)) {
                $pretty = (string) json_encode($decodedAfter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->write('  Body:');
                foreach (explode("\n", $pretty) as $line) {
                    $this->write('    ' . $line);
                }
                $afterErrors = $decodedAfter['errors'] ?? [];
                if ($afterErrors === [] && isset($decodedAfter['data']) && is_array($decodedAfter['data'])) {
                    $afterErrors = $decodedAfter['data']['errors'] ?? [];
                }
                if (is_array($afterErrors) && $afterErrors === [] && $statusAfter >= 200 && $statusAfter < 300) {
                    $this->write('  ✅ Fixed!');
                }
            } else {
                $this->write('  Body:   ' . $afterBody);
            }

            return 0;
        }

        // Audit log for execution
        $replayMode = $editMode ? 'edit' : 'replay';
        $this->auditReplay($traceId, $method, $path, $replayMode);

        /** @var array<string, string> $headers */
        /** @var array<string, mixed> $data */

        // Execute request if forced or edited, otherwise safe-mode warning
        if ($force || $editMode) {
            $this->write('');
            $this->write('  🔄 Replaying ' . $method . ' ' . $path . '...');
            $this->write('  ' . str_repeat('=', 40));
            if ($body !== '' && $body !== '{}') {
                $this->write('  Request Body:');
                $decodedBody = json_decode($body, true);
                if (is_array($decodedBody)) {
                    $prettyBody = (string) json_encode($decodedBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    foreach (explode("\n", $prettyBody) as $line) {
                        $this->write('    ' . $line);
                    }
                } else {
                    $this->write('    ' . $body);
                }
                $this->write('  ' . str_repeat('-', 40));
            }
            $result = $this->executeReplay($method, $url, $body, $headers, $auth, $ct, $data, $insecure);
            $curlError = $this->safeStr($result['error'] ?? '');
            if ($curlError !== '') {
                $this->write('  ❌ curl error: ' . $curlError);
                $this->write('  URL: ' . $url);
                return 1;
            }
            $status = is_numeric($result['status'] ?? null) ? (int) $result['status'] : 0;
            $statusColor = $status >= 500 ? 31 : ($status >= 400 ? 33 : ($status >= 200 && $status < 300 ? 32 : 0));
            $this->write('  Status: ' . ($statusColor ? "\033[{$statusColor}m{$status}\033[0m" : $status));
            $responseBody = $this->safeStr($result['body'] ?? '{}');
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $pretty = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->write('  Response:');
                foreach (explode("\n", $pretty) as $line) {
                    $this->write('    ' . $line);
                }
            } else {
                $this->write('  Response: ' . $responseBody);
            }
            return 0;
        }

        $this->write('⚠ Safe mode: not replaying ' . $method . ' request. Use --force to execute.');
        $this->write('  (Or use --format=curl to see the curl command without executing)');
        if ($format === 'httpie') {
            $this->outputHttpie($method, $url, $body, $headers, $auth);
        } else {
            $this->outputCurl($method, $url, $body, $headers, $auth, $data);
        }

        return 0;
    }

    private function auditReplay(string $traceId, string $method, string $path, string $mode): void
    {
        $auditDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($auditDir)) return;

        $auditFile = $auditDir . DIRECTORY_SEPARATOR . 'replay-audit.log';
        $envVal = getenv('APP_ENV');
        $env = $envVal !== false ? strtolower($envVal) : 'unknown';
        $userVal = getenv('USER');
        if ($userVal === false || $userVal === '') {
            $userVal = getenv('USERNAME');
        }
        $user = $userVal !== false && $userVal !== '' ? $userVal : 'unknown';
        $line = sprintf(
            '[%s] user=%s env=%s mode=%s trace=%s %s %s',
            date('Y-m-d H:i:s'),
            $user,
            $env,
            $mode,
            $traceId,
            $method,
            $path
        );
        file_put_contents($auditFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function executeReplay(string $method, string $url, string $body, array $headers, string $auth, string $ct, array $data, bool $insecure = false): array
    {
        $ch = curl_init($url);
        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) !== 'host' && strtolower((string) $k) !== 'content-length') {
                $curlHeaders[] = (string) $k . ': ' . (string) $v;
            }
        }
        if ($auth !== '') $curlHeaders[] = 'Authorization: ' . $auth;
        if ($ct !== '') $curlHeaders[] = 'Content-Type: ' . $ct;

        /** @var array<int, mixed> $curlOpts */
        $curlOpts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($insecure) {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if ($body !== '' && $body !== '{}') {
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $response, 'error' => $error];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $data
     */
    private function outputCurl(string $method, string $url, string $body, array $headers, string $auth, array $data): void
    {
        $this->write('');
        $this->write('curl \\');
        $this->write('  -X \\');
        $this->write('  ' . escapeshellarg($method) . ' \\');
        $this->write('  ' . escapeshellarg($url) . ' \\');

        $seen = [];
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'host' || $lk === 'content-length' || isset($seen[$lk])) continue;
            $seen[$lk] = true;
            $this->write('  -H \\');
            $this->write('  ' . escapeshellarg((string) $k . ': ' . (string) $v) . ' \\');
        }

        if ($auth !== '') {
            $this->write('  -H \\');
            $this->write('  ' . escapeshellarg('Authorization: ' . $auth) . ' \\');
        }

        if ($body !== '' && $body !== '{}') {
            $this->write('  -d \\');
            $this->write('  ' . escapeshellarg($body));
        } else {
            $this->write('');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function outputHttpie(string $method, string $url, string $body, array $headers, string $auth): void
    {
        $this->write('');
        $httpMethod = $method === 'GET' ? '' : $method;
        $parts = ['http', strtolower($httpMethod), $url];
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'host' || $lk === 'content-length') continue;
            $parts[] = (string) $k . ':' . (string) $v;
        }
        if ($auth !== '') {
            $parts[] = 'Authorization:' . $auth;
        }
        if ($body !== '' && $body !== '{}') {
            $parts[] = 'body=' . $body;
        }
        $this->write('  ' . implode(' ', $parts));
    }

    private function prettyPrint(string $json): string
    {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $json;
    }
}
