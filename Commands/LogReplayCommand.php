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
    use CommandSupport {
        ask as protected traitAsk;
    }

    /** @var \Closure(string): string|null */
    private ?\Closure $inputProvider;
    private bool $curlMissing;

    public function __construct(
        private readonly string $basePath,
        ?\Closure $inputProvider = null,
        bool $curlMissing = false,
    ) {
        $this->inputProvider = $inputProvider;
        $this->curlMissing = $curlMissing;
    }

    /**
     * Read a line of input. When an input provider is injected (tests/automation),
     * it is used instead of reading from the terminal.
     */
    protected function ask(string $question): string
    {
        if ($this->inputProvider !== null) {
            $provider = $this->inputProvider;
            return trim((string) $provider($question));
        }
        return $this->traitAsk($question);
    }

    protected function curlAvailable(): bool
    {
        return !$this->curlMissing && function_exists('curl_init');
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
        $authMode = false;
        $asUser = '';
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

        $argsCount = count($args);
        for ($i = 0; $i < $argsCount; $i++) {
            $arg = $args[$i];
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
            } elseif ($arg === '--set') {
                // Support the documented " --set key=val " (space-separated) form.
                // The key=value pair is the following argument.
                if (isset($args[$i + 1])) {
                    $setArg = $args[$i + 1];
                    $i++;
                    if (str_starts_with($setArg, 'body.')) {
                        $setArg = substr($setArg, 5);
                    }
                    $parts = explode('=', $setArg, 2);
                    if (isset($parts[1])) {
                        $overrides[$parts[0]] = $parts[1];
                    }
                }
            } elseif (str_starts_with($arg, '--seed')) {
                $overrides['_seed'] = '1';
            } elseif ($arg === '--test') {
                // Delegate to make:test --from-trace
                $testArgs = ['siro', 'make:test', '--from-trace=' . $traceId];
                $_SERVER['argv'] = $testArgs;
                $_SERVER['argc'] = count($testArgs);
                $console = new \Siro\Core\Console($this->basePath);
                return $console->run($testArgs);
            } elseif ($arg === '--auth') {
                $authMode = true;
            } elseif (str_starts_with($arg, '--as=')) {
                $asUser = substr($arg, 5);
            }
        }

        if ($traceId === '') {
            $this->write('Usage: php siro log:replay <trace_id> [options]');
            $this->write('');
            $this->write('Options:');
            $this->write('  --format=curl     Output as curl (default)');
            $this->write('  --format=httpie   Output as httpie');
            $this->write('  --force           Execute replay (required for POST/PUT/DELETE or risky traces)');
            $this->write('  --safe            Safe mode: warn on mutating methods (default)');
            $this->write('  --set key=value   Override request field');
            $this->write('  --seed            Seed database from request data');
            $this->write('  --edit            Interactive edit request before replay');
            $this->write('  --diff            Compare before/after responses');
            $this->write('  --auth            Auto-refresh auth before replay');
            $this->write('  --as=email        Login as user before replay');
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

        // Guard: verify file readability before reading
        if (!is_file($traceFile) || !is_readable($traceFile)) {
            $this->write('Trace file is not readable: ' . $traceFile);
            return 1;
        }

        $rawContent = file_get_contents($traceFile);
        if ($rawContent === false) {
            $this->write('Failed to read trace file.');
            return 1;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($rawContent, true);
        if (!is_array($data)) {
            $this->write('Invalid trace file.');
            return 1;
        }

        // Validate required trace fields
        $method = strtoupper($this->safeStr($data['method'] ?? ''));
        if ($method === '') {
            $this->write('Trace file missing required field: method');
            return 1;
        }
        $validMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
        if (!in_array($method, $validMethods, true)) {
            $this->write('Trace file has invalid method: ' . $method);
            return 1;
        }

        $host = $this->safeStr($data['host'] ?? '');
        if ($host === '') {
            $host = 'localhost:8080';
        }
        $path = $this->safeStr($data['path'] ?? '/');
        if ($path === '') {
            $this->write('Trace file missing required field: path');
            return 1;
        }

        // Enterprise hardening: validate the replay target.
        // A tampered trace must not be able to point replay at arbitrary hosts
        // (SSRF) or inject control characters into the URL/path.
        if (!self::isValidHost($host)) {
            $this->write('  âŒ Refusing to replay: invalid host in trace: ' . $host);
            return 1;
        }
        if (!self::isValidPath($path)) {
            $this->write('  âŒ Refusing to replay: invalid characters in path.');
            return 1;
        }

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

        // Default Content-Type for JSON body if missing
        if ($ct === '' && $body !== '' && $body !== '{}' && $body !== '[]') {
            $ct = 'application/json';
        }

        // Auto-auth: refresh token or login
        $authFile = $this->basePath . DIRECTORY_SEPARATOR . '.siro_auth.json';
        if ($authMode || $asUser !== '') {
            if (!$this->curlAvailable()) {
                $this->write('  âŒ PHP curl extension required for --auth/--as=' . $asUser);
                return 1;
            }
            $this->write('');
            $authed = false;
            if ($authMode && file_exists($authFile)) {
                $stored = $this->readAuthFile($authFile);
                if ($stored !== []) {
                    $rt = $this->safeStr($stored['refresh_token'] ?? '');
                    if ($rt !== '') {
                        $newToken = $this->refreshToken($host, $rt);
                        if ($newToken !== null) {
                            $auth = 'Bearer ' . $newToken;
                            $stored['access_token'] = $newToken;
                            $this->writeAuthFile($authFile, $stored);
                            $this->write('  ðŸ”‘ Auth: Bearer [refreshed]');
                            $authed = true;
                        } else {
                            $this->write('  âš  Auth refresh failed â€” token may be expired');
                        }
                    }
                }
            }
            if ($asUser !== '') {
                if (!$authed) {
                    $password = $this->ask('  Password for ' . $asUser . ': ');
                    if ($password === '') {
                        $this->write('  âŒ Password required.');
                        return 1;
                    }
                    $newToken = $this->login($host, $asUser, $password);
                    if ($newToken === null) {
                        $this->write('  âŒ Login failed for ' . $asUser);
                        return 1;
                    }
                    $auth = 'Bearer ' . $newToken;
                    $this->write('  ðŸ”‘ Auth: logged in as ' . $asUser);
                } else {
                    $this->write('  ðŸ”‘ Auth: already refreshed for ' . $asUser);
                }
            }
            $this->write('');
        }

        // --edit: interactive edit
        if ($editMode) {
            $this->write('');
            $this->write('  âœï¸  Edit request body:');
            $this->write('  ' . str_repeat('-', 40));
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($body, true);
            if (is_array($decoded) && $decoded !== []) {
                $this->editRecursive($decoded, '');
            } elseif ($body !== '' && $body !== '{}') {
                // Non-JSON body: show raw text for editing
                $this->write('  (raw body â€” edit as text)');
                $this->write('  \033[33m' . $body . '\033[0m');
                $input = $this->ask("  New value (Enter to keep): ");
                if ($input !== '') {
                    $body = $input;
                }
            } else {
                $this->write('  (empty body â€” no fields to edit)');
                $this->write('  Use --set key=value to add fields');
            }
            if (is_array($decoded)) {
                $body = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                $body = is_string($body) ? $body : '';
            }
            $this->write('  ' . str_repeat('-', 40));
            $this->write('  Updated body: ' . $this->prettyPrint($body));
        }

        // Apply overrides (support dot-notation: items.0.name)
        $seedMode = false;
        foreach ($overrides as $key => $value) {
            if ($key === '_seed') {
                $seedMode = true;
                continue;
            }
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                if (str_contains($key, '.')) {
                    // Dot-notation: items.0.name
                    $keys = explode('.', $key);
                    $ref = &$decoded;
                    foreach ($keys as $k) {
                        if (is_numeric($k)) {
                            $k = (int) $k;
                        }
                        if (!isset($ref[$k]) || !is_array($ref[$k])) {
                            $ref[$k] = [];
                        }
                        $ref = &$ref[$k];
                    }
                    $ref = $value;
                    unset($ref);
                } else {
                    $decoded[$key] = $value;
                }
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
            $this->write('  ðŸ” Dry run â€” no request sent');
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
                $this->write('  âš  Production environment detected â€” auto-switched to dry-run');
                $this->write('  ' . str_repeat('-', 40));
                $this->write('  Method: ' . $method);
                $this->write('  URL:    ' . $url);
                $this->write('  Body:   ' . $body);
                $this->write('  Auth:   Bearer [token present]');
                $this->write('  ' . str_repeat('-', 40));
                $this->write('  To replay: php siro replay ' . $traceId . ' --force  (with confirmation)');
                $this->write('');
                $this->outputCommand($format, $method, $url, $body, $headers, $auth, $data);
                return 1;
            }

            // Dev explicitly passed --force, --edit, or --diff â€” require confirmation
            $mode = $diffMode ? 'diff' : ($editMode ? 'edit' : 'replay');
            $this->write('');
            $this->write('  ' . "\033[41m\033[97m âš  DANGER: Production environment! âš  \033[0m");
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
        if (!$this->curlAvailable()) {
            $this->write('  âŒ PHP curl extension is not installed.');
            $this->write('  Install php-curl to use replay execution.');
            $this->write('  Alternative: use --dry-run to preview, or copy the curl command below.');
            $this->outputCommand($format, $method, $url, $body, $headers, $auth, $data);
            return 1;
        }

        // --diff mode: execute and compare
        if ($diffMode) {
            $this->write('');
            $this->write('  ðŸ”„ Replaying with diff...');
            $this->write('  ' . str_repeat('=', 40));

            $beforeStatusVal = $data['status'] ?? 0;
            $beforeStatus = is_numeric($beforeStatusVal) ? (int) $beforeStatusVal : 0;
            $beforeBody = $this->safeStr($data['response_body'] ?? '');

            $this->analyzeAndDisplayRisks($traceId, $method, $data);
            $this->auditReplay($traceId, $method, $path, 'diff');
            $result = $this->executeReplay($method, $url, $body, $headers, $auth, $ct, $data, $insecure);

            $curlError = $this->safeStr($result['error'] ?? '');
            if ($curlError !== '') {
                $this->write('  âŒ curl error: ' . $curlError);
                $this->write('  URL: ' . $url);
                return 1;
            }

            // Normalize response bodies for deterministic comparison
            $stripMeta = function (array $payload): array {
                unset($payload['debug'], $payload['meta']);
                if (isset($payload['data']) && is_array($payload['data'])) {
                    unset($payload['data']['debug'], $payload['data']['meta']);
                }
                ksort($payload);
                return $payload;
            };

            $beforeStatus = is_numeric($data['status'] ?? null) ? (int) $data['status'] : 0;
            $beforeDecoded = json_decode((string) $beforeBody, true);
            $beforeNorm = is_array($beforeDecoded) ? $stripMeta($beforeDecoded) : null;

            $statusAfter = is_numeric($result['status'] ?? null) ? (int) $result['status'] : 0;
            $afterBody = $this->safeStr($result['body'] ?? '{}');
            $afterDecoded = json_decode($afterBody, true);
            $afterNorm = is_array($afterDecoded) ? $stripMeta($afterDecoded) : null;

            // Detect changes
            $statusChanged = $statusAfter !== $beforeStatus;
            $bodyChanged = $beforeNorm !== null && $afterNorm !== null && json_encode($beforeNorm) !== json_encode($afterNorm);

            $this->write('');
            $this->write('  === BEFORE ===');
            $statusColorBefore = $beforeStatus >= 500 ? 31 : ($beforeStatus >= 400 ? 33 : 32);
            $this->write('  Status: ' . "\033[{$statusColorBefore}m{$beforeStatus}\033[0m");
            if ($beforeNorm !== null) {
                $pretty = (string) json_encode($beforeNorm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->write('  Body:');
                foreach (explode("\n", $pretty) as $line) {
                    $this->write('    ' . $line);
                }
            } else {
                $this->write('  Body:   ' . ($beforeBody !== '' ? $beforeBody : '(empty)'));
            }

            $this->write('');
            $this->write('  === AFTER ===');
            $statusColorAfter = $statusAfter >= 500 ? 31 : ($statusAfter >= 400 ? 33 : ($statusAfter >= 200 && $statusAfter < 300 ? 32 : 0));
            $this->write('  Status: ' . ($statusColorAfter ? "\033[{$statusColorAfter}m{$statusAfter}\033[0m" : $statusAfter));
            if ($afterNorm !== null) {
                $pretty = (string) json_encode($afterNorm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->write('  Body:');
                foreach (explode("\n", $pretty) as $line) {
                    $this->write('    ' . $line);
                }
            } else {
                $this->write('  Body:   ' . ($afterBody !== '' ? $afterBody : '(empty)'));
            }

            // Diff verdict
            if (!$statusChanged && !$bodyChanged) {
                $this->write('  âœ… No changes detected â€” responses match.');
            } elseif ($statusChanged && $statusAfter >= 200 && $statusAfter < 300) {
                $this->write('  âœ… Status changed from ' . $beforeStatus . ' â†’ ' . $statusAfter . ' â€” likely fixed.');
            } elseif ($statusAfter >= 200 && $statusAfter < 300 && $afterNorm !== null && ($afterNorm['success'] ?? false) === true) {
                $this->write('  âœ… Fixed!');
            } else {
                $changes = [];
                if ($statusChanged) $changes[] = "status: {$beforeStatus} â†’ {$statusAfter}";
                if ($bodyChanged) $changes[] = 'response body changed';
                $this->write('  âš  ' . implode(', ', $changes));
            }

            return 0;
        }

        // Analyze trace for side-effect risks
        $hasRisks = $this->analyzeAndDisplayRisks($traceId, $method, $data);

        // Audit log for execution
        $replayMode = $editMode ? 'edit' : 'replay';
        $this->auditReplay($traceId, $method, $path, $replayMode);

        /** @var array<string, string> $headers */
        /** @var array<string, mixed> $data */

        // Guard: require --force when side-effect risks detected or write method
        // GET + no risks → execute immediately
        // POST/PUT/DELETE/PATCH → require --force or --edit
        // Any method + risks detected → require --force
        $isWriteMethod = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $needsGuard = $isWriteMethod || $hasRisks;

        if ($force || $editMode || !$needsGuard) {
            $this->write('');
            $this->write('  ðŸ”„ Replaying ' . $method . ' ' . $path . '...');
            $this->write('  ' . str_repeat('=', 40));
            // Show headers
            $hasAnyHeader = false;
            $headersOutput = [];
            if ($headers !== []) {
                foreach ($headers as $k => $v) {
                    $lk = strtolower((string) $k);
                    if ($lk === 'host' || $lk === 'content-length') continue;
                    $headersOutput[$lk] = $this->safeStr($k) . ': ' . $this->safeStr($v);
                }
            }
            if ($auth !== '' && !isset($headersOutput['authorization'])) {
                $headersOutput['authorization'] = 'Authorization: Bearer [token present]';
            }
            if ($ct !== '' && !isset($headersOutput['content-type'])) {
                $headersOutput['content-type'] = 'Content-Type: ' . $ct;
            }
            if ($headersOutput !== []) {
                $hasAnyHeader = true;
                $this->write('  Headers:');
                foreach ($headersOutput as $h) {
                    $this->write('    ' . $h);
                }
            }
            if (!$hasAnyHeader) {
                $this->write('  Headers: (none captured â€” enable APP_DEBUG=true)');
            }
            // Show body
            $hasBody = $body !== '' && $body !== '{}' && $body !== '[]';
            if ($hasBody) {
                $this->write('  Body:');
                $decodedBody = json_decode($body, true);
                if (is_array($decodedBody)) {
                    $prettyBody = (string) json_encode($decodedBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    foreach (explode("\n", $prettyBody) as $line) {
                        $this->write('    ' . $line);
                    }
                } else {
                    $this->write('    ' . $body);
                }
            }
            $this->write('  ' . str_repeat('-', 40));
            $result = $this->executeReplay($method, $url, $body, $headers, $auth, $ct, $data, $insecure);
            $curlError = $this->safeStr($result['error'] ?? '');
            if ($curlError !== '') {
                $this->write('  âŒ curl error: ' . $curlError);
                $this->write('  URL: ' . $url);
                return 1;
            }
            $status = is_numeric($result['status'] ?? null) ? (int) $result['status'] : 0;
            $statusColor = $status >= 500 ? 31 : ($status >= 400 ? 33 : ($status >= 200 && $status < 300 ? 32 : 0));
            $this->write('  Status: ' . ($statusColor ? "\033[{$statusColor}m{$status}\033[0m" : $status));
            $responseBody = $this->safeStr($result['body'] ?? '{}');

            // Auto-auth: if 401 and trace originally had auth, try to refresh
            // Skip if --safe: auto-auth creates side effects (login request)
            if ($status === 401 && $auth !== '' && !$authMode && $asUser === '' && !in_array('--safe', $args, true)) {
                if ($isProduction) {
                    $this->write('  â›” Production mode: auto-auth disabled for safety.');
                    $this->write('  Run manually: php siro replay ' . $traceId . ' --as=email');
                } else {
                    $this->write('  âš  Original token expired â€” attempting auto-refresh...');
                    /** @var array{token:?string,strategy:string} $authResult */
                    $authResult = $this->autoReauthenticate($host, $data);
                    $newAuth = $authResult['token'];
                    if ($newAuth !== null) {
                        $auth = 'Bearer ' . $newAuth;
                        $this->write('  ðŸ”‘ New token acquired [' . $authResult['strategy'] . '] â†’ retrying...');
                        $result = $this->executeReplay($method, $url, $body, $headers, $auth, $ct, $data, $insecure);
                        $status = is_numeric($result['status'] ?? null) ? (int) $result['status'] : 0;
                        $statusColor = $status >= 500 ? 31 : ($status >= 400 ? 33 : ($status >= 200 && $status < 300 ? 32 : 0));
                        $responseBody = $this->safeStr($result['body'] ?? '{}');
                        $this->write('  Status: ' . ($statusColor ? "\033[{$statusColor}m{$status}\033[0m" : $status));
                    if ($status >= 200 && $status < 300) {
                        $this->write('  âœ… Replay succeeded with refreshed token.');
                    } elseif ($status >= 400 && $status < 500) {
                        $this->write('  â„¹ï¸ Auth OK â€” server returned ' . $status . ' (client error, likely bad request data).');
                    } else {
                        $this->write('  âš  Replay returned ' . $status . ' even after auth refresh.');
                    }
                    } else {
                        $this->write('  âš  Could not auto-refresh. Try manual login:');
                        $manualEmail = $this->ask('  Email/username: ');
                        if ($manualEmail !== '') {
                            $this->write('  Password (input hidden): ');
                            $manualPass = $this->readPassword();
                            if ($manualPass !== '') {
                                $manualToken = $this->loginDevOnly($host, $manualEmail, $manualPass);
                                if ($manualToken !== null) {
                                    $auth = 'Bearer ' . $manualToken;
                                    $this->write('  ðŸ”‘ Logged in as ' . $manualEmail . ' â†’ retrying...');
                                    $result = $this->executeReplay($method, $url, $body, $headers, $auth, $ct, $data, $insecure);
                                    $status = is_numeric($result['status'] ?? null) ? (int) $result['status'] : 0;
                                    $statusColor = $status >= 500 ? 31 : ($status >= 400 ? 33 : ($status >= 200 && $status < 300 ? 32 : 0));
                                    $responseBody = $this->safeStr($result['body'] ?? '{}');
                                    $this->write('  Status: ' . ($statusColor ? "\033[{$statusColor}m{$status}\033[0m" : $status));
                                if ($status >= 200 && $status < 300) {
                                    $this->write('  âœ… Replay succeeded.');
                                } elseif ($status >= 400 && $status < 500) {
                                    $this->write('  â„¹ï¸ Auth OK â€” server returned ' . $status . ' (client error).');
                                }
                                } else {
                                    $this->write('  âŒ Login failed. Try: php siro replay ' . $traceId . ' --as=email');
                                }
                            }
                        }
                    }
                }
            }

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

        $this->write('  Run with --force to execute, or --edit to modify body before replay.');
        $this->outputCommand($format, $method, $url, $body, $headers, $auth, $data);

        return 0;
    }

    /**
     * Analyze trace for potential side effects and display risk summary.
     * Advisory only — detection is based on captured execution context.
     *
     * @param array<string, mixed> $data
     */
    private function analyzeAndDisplayRisks(string $traceId, string $method, array $data): bool
    {
        $dbWrites = 0;
        $httpCalls = 0;
        $queueJobs = 0;

        // Detect DB write operations from captured queries
        if (isset($data['queries']) && is_array($data['queries'])) {
            foreach ($data['queries'] as $query) {
                $sql = strtoupper(is_string($query['sql'] ?? null) ? $query['sql'] : '');
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/', $sql)) {
                    $dbWrites++;
                }
            }
        }

        // Detect outbound HTTP calls
        if (isset($data['outbound_http']) && is_array($data['outbound_http'])) {
            $httpCalls = count($data['outbound_http']);
        }

        // Detect queue dispatches
        if (isset($data['queue_jobs']) && is_array($data['queue_jobs'])) {
            $queueJobs = count($data['queue_jobs']);
        }

        // Detect destructive HTTP method
        $destructiveMethod = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        $hasRisks = $dbWrites > 0 || $httpCalls > 0 || $queueJobs > 0 || $destructiveMethod;

        if (!$hasRisks) {
            return false;
        }

        $this->write('');
        $this->write('  Potential replay side effects:');
        $this->write('  ' . str_repeat('-', 40));
        if ($dbWrites > 0) {
            $this->write('  Database writes:   ' . $dbWrites);
        }
        if ($httpCalls > 0) {
            $this->write('  Outbound HTTP:     ' . $httpCalls);
        }
        if ($queueJobs > 0) {
            $this->write('  Queue dispatches:  ' . $queueJobs);
        }
        if ($destructiveMethod && $dbWrites === 0 && $httpCalls === 0 && $queueJobs === 0) {
            $this->write('  Non-idempotent method: ' . $method);
        }
        $this->write('  ' . str_repeat('-', 40));
        $this->write('  WARNING: This command re-executes the request against the target application.');
        $this->write('  Database writes, external API calls, queued jobs, emails, or other');
        $this->write('  application side effects may occur again.');
        $this->write('  Side-effect detection is advisory and based on captured trace context.');
        $this->write('');

        return true;
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
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Failed to initialize curl'];
        }
        $curlHeaders = [];
        $hasContentType = false;
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'host' || $lk === 'content-length') continue;
            if ($lk === 'authorization') continue;
            if ($lk === 'content-type') $hasContentType = true;
            $curlHeaders[] = (string) $k . ': ' . (string) $v;
        }
        if ($auth !== '') $curlHeaders[] = 'Authorization: ' . $auth;
        if ($ct !== '' && !$hasContentType) $curlHeaders[] = 'Content-Type: ' . $ct;

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
        $errno = curl_errno($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Classify curl errors
        if ($response === false || $error !== '') {
            $errorType = match (true) {
                $errno === CURLE_OPERATION_TIMEDOUT || $errno === CURLE_COULDNT_CONNECT => 'timeout',
                $errno === CURLE_COULDNT_RESOLVE_HOST => 'dns',
                $errno === CURLE_GOT_NOTHING => 'connection_reset',
                default => 'network',
            };
            $error = "[{$errorType}] " . $error;
        }
        return ['status' => $status, 'body' => $response, 'error' => $error, 'errno' => $errno];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $data
     */
    private function outputCurl(string $method, string $url, string $body, array $headers, string $auth, array $data): void
    {
        // Quote a value for curl display (single quotes for cross-platform safety)
        $q = function (string $s): string {
            // Use single quotes to avoid shell escaping issues with JSON
            return "'" . str_replace("'", "'\\''", $s) . "'";
        };

        $cmd = 'curl -X ' . $method . ' ' . $q($url);

        $seen = [];
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'host' || $lk === 'content-length' || isset($seen[$lk])) continue;
            $seen[$lk] = true;
            $cmd .= " \\\n    -H " . $q((string) $k . ': ' . (string) $v);
        }

        if ($auth !== '' && !isset($seen['authorization'])) {
            $seen['authorization'] = true;
            $cmd .= " \\\n    -H " . $q('Authorization: ' . $auth);
        }

        if ($body !== '' && $body !== '[]' && $body !== '{}') {
            $cmd .= " \\\n    -d " . $q($body);
        }

        $this->write('');
        $this->write('  ' . $cmd);

        // Show what data is available from the trace
        $this->write('');
        $hasHeaders = $headers !== [] || $auth !== '';
        $hasBody = $body !== '' && $body !== '[]' && $body !== '{}';
        if (!$hasHeaders && !$hasBody) {
            $this->write('  âš  This trace has limited data (no headers/body captured).');
            $this->write('  Enable APP_DEBUG=true in .env for full capture.');
        } else {
            if ($hasHeaders) {
                $this->write('  Headers: ' . (count($headers) + ($auth !== '' ? 1 : 0)) . ' included');
            }
            if ($hasBody) {
                $this->write('  Body: ' . strlen($body) . ' bytes');
            }
        }
    }

    /**
     * Dispatch the request preview to the requested output format.
     * Supports 'curl' (default) and 'httpie'.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $data
     */
    private function outputCommand(string $format, string $method, string $url, string $body, array $headers, string $auth, array $data): void
    {
        if ($format === 'httpie') {
            $this->outputHttpie($method, $url, $body, $headers, $auth);
        } else {
            $this->outputCurl($method, $url, $body, $headers, $auth, $data);
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

    /**
     * Recursively flatten nested arrays for --edit mode.
     * Flattens: {"user":{"name":"A","addr":{"city":"B"}}} â†’ user.name, user.addr.city
     */
    /**
     * @param array<mixed> $data
     */
    private function editRecursive(array &$data, string $prefix): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
            if (is_array($value) && $value !== []) {
                $this->write('  ' . $fullKey . ': (object)');
                $this->editRecursive($value, $fullKey);
                $data[$key] = $value;
            } else {
                $display = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : json_encode($value));
                $this->write('  ' . $fullKey . ': \033[33m' . $display . '\033[0m');
                $input = $this->ask("  New value (Enter to keep): ");
                if ($input !== '') {
                    // Try to preserve types: numeric, bool, null
                    if ($input === 'true') { $data[$key] = true; }
                    elseif ($input === 'false') { $data[$key] = false; }
                    elseif ($input === 'null') { $data[$key] = null; }
                    elseif (is_numeric($input)) { $data[$key] = str_contains($input, '.') ? (float) $input : (int) $input; }
                    else { $data[$key] = $input; }
                }
            }
        }
    }

    /**
     * Auto-discover auth config from model + routes + migrations.
     *
     * Reads:
     *   1. Model User -> table, fillable (email_field, pass_field)
     *   2. Routes file -> login endpoint
     *   3. Migration -> auth identifier columns
     *
     * No .env needed - auto-detects the project structure.
     *
     * @return array{endpoint:string,email_field:string,pass_field:string,token_path:string,refresh_endpoint:string}
     */
    private function discoverAuthConfig(): array
    {
        $endpoint = '/api/auth/login';
        $emailField = 'email';
        $passField = 'password';
        $tokenPath = 'data.token';
        $refreshEndpoint = '/api/auth/refresh';

        // 1) Discover from User model
        $userModelClass = 'App\\Models\\User';
        if (class_exists($userModelClass)) {
            try {
                /** @phpstan-ignore-next-line ReflectionClass accepts class-string */
                $ref = new \ReflectionClass($userModelClass);
                $instance = $ref->newInstanceWithoutConstructor();
                // Read table name
                $tableProp = $ref->getProperty('table');
                $tableProp->setAccessible(true);
                $table = $tableProp->getValue($instance);
                if (is_string($table) && $table !== '' && $table !== 'users') {
                    // If table differs from users, derive the matching endpoint
                    $singular = rtrim($table, 's');
                    $endpoint = '/api/' . $singular . '/auth/login';
                    $refreshEndpoint = '/api/' . $singular . '/auth/refresh';
                }
                // Read fillable to determine the login field
                $fillableProp = $ref->getProperty('fillable');
                $fillableProp->setAccessible(true);
                $fillable = $fillableProp->getValue($instance);
                if (is_array($fillable)) {
                    // Find a field like email/username/login
                    $loginCandidates = ['email', 'username', 'login', 'phone', 'mobile'];
                    foreach ($loginCandidates as $candidate) {
                        if (in_array($candidate, $fillable, true)) {
                            $emailField = $candidate;
                            break;
                        }
                    }
                    // Find a field like password/passwd/pass
                    $passCandidates = ['password', 'passwd', 'pass', 'secret'];
                    foreach ($passCandidates as $candidate) {
                        if (in_array($candidate, $fillable, true)) {
                            $passField = $candidate;
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Silent fallback
            }
        }

        // 2) Discover from routes file
        $routesFile = $this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php';
        if (file_exists($routesFile)) {
            $routesContent = (string) file_get_contents($routesFile);
            // Find pattern: $router->post('...', [AuthController::class, 'login'])
            if (preg_match('/\$router->post\((["\'])([^"\']+login[^"\']*)\1/', $routesContent, $m)) {
                $found = $m[2];
                // Check if endpoint differs from default
                if ($found !== '/auth/login') {
                    $endpoint = $found;
                    // Derive refresh endpoint from login endpoint
                    $refreshEndpoint = str_replace('/login', '/refresh', $found);
                    $refreshEndpoint = str_replace('/signin', '/refresh', $refreshEndpoint);
                }
            }
            // Find field names from validate() in AuthController
            $authControllerFile = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'AuthController.php';
            if (file_exists($authControllerFile)) {
                $authContent = (string) file_get_contents($authControllerFile);
                if (preg_match('/\'(email|username|login)\'\s*=>\s*\'required\|email/', $authContent, $m)) {
                    $emailField = $m[1];
                }
            }
        }

        // 3) Discover from migration files - read users table schema
        $migrationsDir = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (is_dir($migrationsDir)) {
            $migrationFiles = glob($migrationsDir . '/*.php') ?: [];
            foreach ($migrationFiles as $mf) {
                $content = (string) file_get_contents($mf);
                if (str_contains($content, 'users')) {
                    // Find email/username/phone columns in migration
                    if (preg_match('/\$t->string\((["\'])(email|username|login|phone|mobile)\1\)/', $content, $m)) {
                        $emailField = $m[2];
                    }
                }
            }
        }

        // 4) Check response token path from AuthController
        $authControllerFile = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'AuthController.php';
        if (file_exists($authControllerFile)) {
            $authContent = (string) file_get_contents($authControllerFile);
            // Find pattern: return Response::created(['token' => ..., ...])
            if (preg_match('/\[\s*[\'"]token[\'"]\s*=>/', $authContent)) {
                $tokenPath = 'data.token';
            } elseif (preg_match('/\[\s*[\'"]api_token[\'"]\s*=>/', $authContent)) {
                $tokenPath = 'data.api_token';
            } elseif (preg_match('/\[\s*[\'"]access_token[\'"]\s*=>/', $authContent)) {
                $tokenPath = 'data.access_token';
            }
        }

        return [
            'endpoint' => $endpoint,
            'email_field' => $emailField,
            'pass_field' => $passField,
            'token_path' => $tokenPath,
            'refresh_endpoint' => $refreshEndpoint,
        ];
    }

    /**
     * Read auth config in priority order: .env > auto-discover > defaults.
     * @return array{endpoint:string,email_field:string,pass_field:string,token_path:string,refresh_endpoint:string}
     */
    private function authConfig(): array
    {
        // Step 1: auto-discover from code
        $cfg = $this->discoverAuthConfig();

        // Step 2: .env overrides (explicit config always wins)
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($envFile)) {
            $c = (string) file_get_contents($envFile);
            $m = null;
            if (preg_match('/^AUTH_ENDPOINT\s*=\s*(.+)$/m', $c, $m)) $cfg['endpoint'] = trim($m[1]);
            if (preg_match('/^AUTH_EMAIL_FIELD\s*=\s*(.+)$/m', $c, $m)) $cfg['email_field'] = trim($m[1]);
            if (preg_match('/^AUTH_PASSWORD_FIELD\s*=\s*(.+)$/m', $c, $m)) $cfg['pass_field'] = trim($m[1]);
            if (preg_match('/^AUTH_TOKEN_PATH\s*=\s*(.+)$/m', $c, $m)) $cfg['token_path'] = trim($m[1]);
            if (preg_match('/^AUTH_REFRESH_ENDPOINT\s*=\s*(.+)$/m', $c, $m)) $cfg['refresh_endpoint'] = trim($m[1]);
        }

        return $cfg;
    }

    /**
     * Extract token from response using configured token_path.
     * Supports dot-notation: "data.token", "access_token", etc.
     * Auto-fallback through common paths if the main path is not found.
     *
     * @param array<string, mixed> $response
     */
    private function extractToken(array $response, string $tokenPath): ?string
    {
        // Try the main path first
        $keys = explode('.', $tokenPath);
        $current = $response;
        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) { $current = null; break; }
            $current = $current[$key];
        }
        if (is_string($current) && $current !== '') return $current;

        // Fallback: try all common paths
        $fallbackPaths = [
            'data.token', 'token', 'access_token', 'data.access_token',
            'data.jwt', 'jwt', 'data.api_token', 'api_token',
            'data.accessToken', 'accessToken',
        ];
        foreach ($fallbackPaths as $fb) {
            if ($fb === $tokenPath) continue;
            $keys = explode('.', $fb);
            $current = $response;
            $ok = true;
            foreach ($keys as $key) {
                if (!is_array($current) || !isset($current[$key])) { $ok = false; break; }
                $current = $current[$key];
            }
            if ($ok && is_string($current) && $current !== '') return $current;
        }

        return null;
    }

    /**
     * Refresh auth token using stored refresh token.
     */
    private function refreshToken(string $host, string $refreshToken): ?string
    {
        $cfg = $this->authConfig();
        $url = 'http://' . $host . $cfg['refresh_endpoint'];
        $ch = curl_init($url);
        if ($ch === false) return null;
        $body = json_encode(['refresh_token' => $refreshToken]);
        if ($body === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 200 && $status < 300) {
            /** @var array<string, mixed>|null $result */
            $result = json_decode((string) $response, true);
            if (is_array($result)) {
                $token = $this->extractToken($result, $cfg['token_path']);
                if ($token !== null) return $token;
                foreach (['access_token', 'token', 'data.access_token'] as $p) {
                    $t = $this->extractToken($result, $p);
                    if ($t !== null) return $t;
                }
            }
        }
        return null;
    }

    /**
     * Login in dev mode only â€” stores token but NEVER raw password.
     */
    private function loginDevOnly(string $host, string $email, string $password): ?string
    {
        $token = $this->login($host, $email, $password);
        if ($token !== null) {
            $authFile = $this->basePath . DIRECTORY_SEPARATOR . '.siro_auth.json';
            /** @var array<string, mixed> $stored */
            $stored = [];
            if (file_exists($authFile)) {
                $stored = $this->readAuthFile($authFile);
            }
            $stored['email'] = $email;
            $stored['access_token'] = $token;
            if (empty($stored['refresh_token'])) {
                $stored['refresh_token'] = '';
            }
            $this->writeAuthFile($authFile, $stored);
        }
        return $token;
    }

    /**
     * Read password from stdin without echoing to terminal.
     * Falls back to plain ask() if no hidden-input method available.
     */
    private function readPassword(): string
    {
        if ($this->inputProvider !== null) {
            $provider = $this->inputProvider;
            return trim((string) $provider(''));
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use inline script to read hidden input
            $psCmd = 'powershell -Command "$p=Read-Host -AsSecureString; $r=[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p)); Write-Output $r"';
            $output = shell_exec($psCmd);
            return is_string($output) ? trim($output) : '';
        }
        // Linux/Mac: use stty -echo
        $orig = shell_exec('stty -g 2>/dev/null');
        if ($orig !== null) {
            shell_exec('stty -echo 2>/dev/null');
        }
        $pass = trim(fgets(STDIN) ?: '');
        if ($orig !== null) {
            shell_exec('stty ' . $orig . ' 2>/dev/null');
        }
        echo PHP_EOL;
        return $pass;
    }

    /**
     * Login and store tokens. Uses configurable endpoint + field names.
     * Never stores raw password in .siro_auth.json - only stores refresh_token.
     */
    private function login(string $host, string $email, string $password): ?string
    {
        $cfg = $this->authConfig();
        $url = 'http://' . $host . $cfg['endpoint'];
        $ch = curl_init($url);
        if ($ch === false) return null;
        $body = json_encode([$cfg['email_field'] => $email, $cfg['pass_field'] => $password]);
        if ($body === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 200 && $status < 300) {
            /** @var array<string, mixed>|null $result */
            $result = json_decode((string) $response, true);
            if (is_array($result)) {
                $token = $this->extractToken($result, $cfg['token_path']);
                if ($token === null) {
                    foreach (['data.token', 'access_token', 'token', 'data.access_token'] as $p) {
                        $t = $this->extractToken($result, $p);
                        if ($t !== null) { $token = $t; break; }
                    }
                }
                if ($token !== null) {
                    $authFile = $this->basePath . DIRECTORY_SEPARATOR . '.siro_auth.json';
                    /** @var array<string, mixed> $stored */
                    $stored = [];
                    if (file_exists($authFile)) {
                        $stored = $this->readAuthFile($authFile);
                    }
                    $stored['email'] = $email;
                    $stored['access_token'] = $token;
                    $stored['refresh_token'] = $this->safeStr(is_array($result['data'] ?? null) ? ($result['data']['refresh_token'] ?? '') : ($result['refresh_token'] ?? ''));
                    // Remove old password if present
                    unset($stored['password']);
                    $this->writeAuthFile($authFile, $stored);
                    return $token;
                }
            }
        }
        // fallback: try with stream_context (different curl backend)
        $fallbackBody = json_encode([$cfg['email_field'] => $email, $cfg['pass_field'] => $password]);
        if ($fallbackBody === false) return null;
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $fallbackBody,
                'timeout' => 10,
            ],
        ]);
        $fbResponse = @file_get_contents($url, false, $context);
        if ($fbResponse === false) return null;
        /** @var array<string, mixed>|null $fbResult */
        $fbResult = json_decode($fbResponse, true);
        if (!is_array($fbResult)) return null;
        $token = $this->extractToken($fbResult, $cfg['token_path']);
        if ($token === null) {
            foreach (['data.token', 'access_token', 'token'] as $p) {
                $t = $this->extractToken($fbResult, $p);
                if ($t !== null) { $token = $t; break; }
            }
        }
        return $token;
    }

    /**
     * Auto-reauthenticate when replay gets 401.
     *
     * 6-step fallback chain. Always has a final strategy (interactive prompt).
     *
     * @param array<string, mixed> $data
     * @return array{token:?string,strategy:string}
     */
    private function autoReauthenticate(string $host, array $data): array
    {
        $authFile = $this->basePath . DIRECTORY_SEPARATOR . '.siro_auth.json';
        $cfg = $this->authConfig();
        $strategy = 'no_auth';

        // Load .env for admin credentials
        $adminEmail = '';
        $adminPassword = '';
        $envFile = $this->basePath . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($envFile)) {
            $c = (string) file_get_contents($envFile);
            $m = null;
            if (preg_match('/^ADMIN_EMAIL\s*=\s*(.+)$/m', $c, $m)) $adminEmail = trim($m[1]);
            if (preg_match('/^ADMIN_PASSWORD\s*=\s*(.+)$/m', $c, $m)) $adminPassword = trim($m[1]);
        }

        // 1) Try stored credentials
        if (file_exists($authFile)) {
            /** @var array<string, mixed> $stored */
            $stored = $this->readAuthFile($authFile);
            if ($stored !== []) {
                $email = $this->safeStr($stored['email'] ?? '');
                $rt = $this->safeStr($stored['refresh_token'] ?? '');
                $pw = $this->safeStr($stored['password'] ?? '');
                // Try refresh token first
                if ($rt !== '') {
                    $newToken = $this->refreshToken($host, $rt);
                    if ($newToken !== null) {
                        $stored['access_token'] = $newToken;
                        $this->writeAuthFile($authFile, $stored);
                        return ['token' => $newToken, 'strategy' => 'refresh_token'];
                    }
                    $strategy = 'refresh_token_failed';
                }
                // Try full login (password from old .siro_auth.json - removed after use)
                if ($email !== '' && $pw !== '') {
                    $newToken = $this->login($host, $email, $pw);
                    if ($newToken !== null) {
                        $csDecoded = $this->readAuthFile($authFile);
                        if ($csDecoded !== []) {
                            unset($csDecoded['password']);
                            $this->writeAuthFile($authFile, $csDecoded);
                        }
                        return ['token' => $newToken, 'strategy' => 'stored_credentials'];
                    }
                    $strategy = 'stored_credentials_failed';
                }
            }
        }

        // 2) Try ADMIN_EMAIL / ADMIN_PASSWORD from .env (must be explicitly configured)
        if ($adminEmail !== '' && $adminPassword !== '') {
            $newToken = $this->login($host, $adminEmail, $adminPassword);
            if ($newToken !== null) return ['token' => $newToken, 'strategy' => 'env_admin'];
        } else {
            $this->warn("ADMIN_EMAIL and ADMIN_PASSWORD not set in .env. Auto-auth requires explicit credentials.");
        }

        // 3) Register a new user via API (last resort â€” dev only)
        $isLocal = !in_array((string) getenv('APP_ENV'), ['production', 'prod', 'staging'], true);
        if ($isLocal) {
            $email = 'replay-' . bin2hex(random_bytes(4)) . '@siro.local';
            $password = 'siro-replay-123';
            $regBody = json_encode([
                'name' => 'Replay User',
                $cfg['email_field'] => $email,
                $cfg['pass_field'] => $password,
                $cfg['pass_field'] . '_confirmation' => $password,
            ]);
            if ($regBody !== false) {
                $regEndpoint = str_replace('/login', '/register', $cfg['endpoint']);
                $regEndpoint = str_replace('/signin', '/signup', $regEndpoint);
                $ch = curl_init('http://' . $host . $regEndpoint);
                if ($ch !== false) {
                    curl_setopt_array($ch, [
                        CURLOPT_CUSTOMREQUEST => 'POST',
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_POSTFIELDS => $regBody,
                    ]);
                    curl_exec($ch);
                    $regStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($regStatus >= 200 && $regStatus < 300) {
                        $newToken = $this->login($host, $email, $password);
                        if ($newToken !== null) return ['token' => $newToken, 'strategy' => 'register_new'];
                    }
                }
            }
            $strategy = 'register_failed';
        }

        return ['token' => null, 'strategy' => $strategy];
    }

    /**
     * Write auth file with LOCK_EX to prevent race conditions.
     * @param array<string, mixed> $data
     */
    private function writeAuthFile(string $path, array $data): void
    {
        // Encrypt sensitive tokens so .siro_auth.json isn't a plaintext
        // credential dump on disk (enterprise hardening).
        $key = (string) \Siro\Core\Env::get('APP_KEY', '');
        if ($key !== '') {
            try {
                if (isset($data['refresh_token']) && is_string($data['refresh_token']) && $data['refresh_token'] !== '') {
                    $data['refresh_token'] = 'enc:' . \Siro\Core\Encrypter::encrypt($data['refresh_token']);
                }
                if (isset($data['access_token']) && is_string($data['access_token']) && $data['access_token'] !== '') {
                    $data['access_token'] = 'enc:' . \Siro\Core\Encrypter::encrypt($data['access_token']);
                }
            } catch (\Throwable $e) {
                // Fall back to plaintext if encryption fails (dev without key)
            }
        }
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            @file_put_contents($path, $encoded, LOCK_EX);
        }
    }

    /**
     * Read and decrypt the auth file (handles both encrypted and legacy plaintext).
     *
     * @return array<string, mixed>
     */
    private function readAuthFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $stored = json_decode((string) file_get_contents($path), true);
        if (!is_array($stored)) {
            return [];
        }
        /** @var array<string, mixed> $stored */
        $key = (string) \Siro\Core\Env::get('APP_KEY', '');
        if ($key !== '') {
            try {
                foreach (['refresh_token', 'access_token'] as $field) {
                    if (isset($stored[$field]) && is_string($stored[$field]) && str_starts_with($stored[$field], 'enc:')) {
                        $stored[$field] = \Siro\Core\Encrypter::decrypt(substr($stored[$field], 4));
                    }
                }
            } catch (\Throwable $e) {
                // Leave as-is if decryption fails (e.g. key changed)
            }
        }
        return $stored;
    }

    /**
     * Validate a replay target host string. Prevents SSRF / URL-breaking
     * injection from tampered trace files.
     */
    public static function isValidHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        // host[:port] or [ipv6]:port â€” letters, digits, dots, dashes, colons, brackets
        if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host)) {
            return false;
        }
        // Port must be numeric and in range
        $hostPart = $host;
        $port = 0;
        if (str_starts_with($host, '[')) {
            // IPv6: [::1]:8080
            if (preg_match('/^\[[0-9A-Fa-f:.]+\](?::(\d+))?$/', $host, $m)) {
                $port = isset($m[1]) ? (int) $m[1] : 0;
                return $port === 0 || ($port >= 1 && $port <= 65535);
            }
            return false;
        }
        if (substr_count($host, ':') === 1) {
            [$hostPart, $portStr] = explode(':', $host, 2);
            if (!ctype_digit($portStr)) {
                return false;
            }
            $port = (int) $portStr;
            if ($port < 1 || $port > 65535) {
                return false;
            }
        }
        // hostname: letters, digits, dots, dashes
        return preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/', $hostPart) === 1;
    }

    /**
     * Validate a replay path â€” reject control characters and whitespace that
     * could break out of the URL or inject header lines.
     */
    public static function isValidPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        return preg_match('/[\x00-\x1F\x7F\s]/', $path) !== 1;
    }
}
