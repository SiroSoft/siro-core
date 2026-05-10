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
            $this->write('  --force           Skip safe-mode warning (dangerous)');
            $this->write('  --safe            Safe mode: warn on non-GET (default)');
            $this->write('  --set key=value   Override request field');
            $this->write('  --seed            Seed database from request data');
            $this->write('  --edit            Interactive edit request before replay');
            $this->write('  --diff            Compare before/after responses');
            $this->write('');
            $this->write('Examples:');
            $this->write('  php siro log:replay siro_a1b2');
            $this->write('  php siro log:replay siro_a1b2 --force');
            $this->write('  php siro log:replay siro_a1b2 --set user_id=1');
            $this->write('  php siro log:replay siro_a1b2 --edit');
            $this->write('  php siro log:replay siro_a1b2 --diff');
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

        // --edit: interactive edit
        if ($editMode) {
            $this->write('');
            $this->write('  ✏️  Edit request body:');
            $this->write('  ' . str_repeat('-', 40));
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $this->write("  {$key}: \033[33m{$value}\033[0m");
                    $input = readline("  New value (Enter to keep): ");
                    if ($input !== '') {
                        $decoded[$key] = $input;
                    }
                }
            }
            $body = (string) json_encode($decoded ?? [], JSON_UNESCAPED_UNICODE);
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
                $body = json_encode($decoded);
            }
        }

        if ($seedMode) {
            $seedData = json_decode($body, true);
            if (is_array($seedData)) {
                unset($seedData['id'], $seedData['created_at'], $seedData['updated_at']);
                $this->write('Seed command:');
                $this->write((string) '  $db->table("' . ($data['table'] ?? 'table') . '")->insert(' . json_encode($seedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ');');
                $this->write('  Then run: php siro db:seed');
            }
            return 0;
        }

        // Production safety: block unsafe replay
        if ($isProduction && !$dryRun && !$diffMode && !$editMode) {
            $this->write('');
            $this->write('  ⚠ Production environment detected!');
            $this->write('  Replaying requests on production is blocked by default.');
            $this->write('');
            $this->write('  Allowed on production:');
            $this->write('    php siro replay ' . $traceId . ' --dry-run    # View only (safe)');
            $this->write('    php siro replay ' . $traceId . ' --diff       # Compare (safe)');
            $this->write('');
            $this->write('  To force replay (NOT recommended on production):');
            $this->write('    export APP_ENV=local && php siro replay ' . $traceId . ' --force');
            $this->write('');
            if ($method !== 'GET') {
                $this->write('  ❌ Blocked: ' . $method . ' ' . $path . ' would modify data.');
                return 1;
            }
        }

        // --dry-run: just show what would be replayed
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

        // --diff mode
        if ($diffMode) {
            $this->write('');
            $this->write('  🔄 Replaying with diff...');
            $this->write('  ' . str_repeat('=', 40));

            $beforeStatus = $data['status'] ?? 0;
            $beforeBody = $data['response_body'] ?? '';

            $result = $this->executeReplay($method, $url, $body, $headers, $auth, $ct, $data);

            $this->write('');
            $this->write('  === BEFORE ===');
            $this->write('  Status: ' . $beforeStatus);
            $decodedBefore = json_decode((string) $beforeBody, true);
            $beforeErrors = $decodedBefore['errors'] ?? ($decodedBefore['data']['errors'] ?? []);
            if ($beforeErrors !== []) {
                foreach ($beforeErrors as $f => $m) {
                    $msgs = implode(', ', (array) $m);
                    $this->write('  ❌ ' . $f . ': ' . $msgs);
                }
            }

            $this->write('');
            $this->write('  === AFTER ===');
            $this->write('  Status: ' . $result['status']);
            $decodedAfter = json_decode((string) ($result['body'] ?? '{}'), true);
            $afterErrors = $decodedAfter['errors'] ?? ($decodedAfter['data']['errors'] ?? []);
            if ($afterErrors !== []) {
                foreach ($afterErrors as $f => $m) {
                    $msgs = implode(', ', (array) $m);
                    $this->write('  ❌ ' . $f . ': ' . $msgs);
                }
            } elseif ((int) $result['status'] >= 200 && (int) $result['status'] < 300) {
                $this->write('  ✅ Fixed!');
            }

            return 0;
        }

        // Audit log for all replay operations
        $replayMode = $editMode ? 'edit' : 'replay';
        $this->auditReplay($traceId, $method, $path, $replayMode);

        // Normal replay (curl output or execute)
        if (!$force && $method !== 'GET') {
            $this->write('⚠ Safe mode: not replaying ' . $method . ' request. Use --force to execute.');
            $this->write('  (Or use --format=curl to see the curl command without executing)');
        }

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
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function executeReplay(string $method, string $url, string $body, array $headers, string $auth, string $ct, array $data): array
    {
        $ch = curl_init($url);
        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) !== 'host' && strtolower((string) $k) !== 'content-length') {
                $curlHeaders[] = $k . ': ' . $v;
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
            CURLOPT_POSTFIELDS => $body,
        ];
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $response];
    }

    /**
     * @param array<string, mixed> $headers
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
            $this->write('  ' . escapeshellarg($k . ': ' . $v) . ' \\');
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
     * @param array<string, mixed> $headers
     */
    private function outputHttpie(string $method, string $url, string $body, array $headers, string $auth): void
    {
        $this->write('');
        $httpMethod = $method === 'GET' ? '' : $method;
        $parts = ['http', strtolower($httpMethod), $url];
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'host' || $lk === 'content-length') continue;
            $parts[] = $k . ':' . $v;
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
