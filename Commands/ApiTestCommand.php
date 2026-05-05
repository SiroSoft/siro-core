<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Router;
use Siro\Core\Lang;
use Siro\Core\ValidationException;

final class ApiTestCommand
{
    use CommandSupport;

    private string $authFile;
    private string $historyFile;
    private string $collectionFile;

    public function __construct(private readonly string $basePath)
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        $this->authFile = $dir . DIRECTORY_SEPARATOR . 'api-test-auth.json';
        $this->historyFile = $dir . DIRECTORY_SEPARATOR . 'api-test-history.json';
        $this->collectionFile = $dir . DIRECTORY_SEPARATOR . 'api-test-collections.json';
    }

    public function run(array $args): int
    {
        if ($args === []) {
            $this->printHelp();
            return 0;
        }

        // ─── History ──────────────────────
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

        // ─── Collection list ──────────────
        if (in_array('--collection-list', $args, true)) {
            return $this->listCollections();
        }

        // ─── Collection run ───────────────
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--collection=')) {
                $name = substr($arg, 13);
                return $this->runCollection($name);
            }
        }

        // ─── Normal request ───────────────
        $method = strtoupper($args[0] ?? '');
        $path = $args[1] ?? '';

        if ($method === '' || $path === '') {
            $this->write('Error: Method and path are required.');
            $this->write('Usage: php siro api:test GET /api/users');
            return 1;
        }

        $fields = [];
        $customHeaders = [];
        $as = null;
        $contentType = 'json';
        $watch = false;
        $collectionSave = null;
        $login = false;
        $loop = 1;

        for ($i = 2; $i < count($args); $i++) {
            $arg = $args[$i];
            if ($arg === '--json') {
                $contentType = 'json';
            } elseif ($arg === '--form') {
                $contentType = 'form';
            } elseif (str_starts_with($arg, '--header=')) {
                $customHeaders[] = substr($arg, 9);
            } elseif (str_starts_with($arg, '--as=')) {
                $as = substr($arg, 5);
            } elseif ($arg === '--login') {
                $login = true;
            } elseif ($arg === '--watch') {
                $watch = true;
            } elseif (str_starts_with($arg, '--loop=')) {
                $loop = max(1, (int) substr($arg, 7));
            } elseif (str_starts_with($arg, '--collection-save=')) {
                $collectionSave = substr($arg, 18);
            } elseif (str_starts_with($arg, '--body=')) {
                // --body key=value format
                $bodyArg = substr($arg, 7);
                if (str_contains($bodyArg, '=')) {
                    $parts = explode('=', $bodyArg, 2);
                    $fields[$parts[0]] = $parts[1];
                }
            } elseif (str_starts_with($arg, "--body=")) {
                // --body '{"json":"payload"}'
                $bodyVal = substr($arg, 7);
                if (str_starts_with($bodyVal, '{')) {
                    $decoded = json_decode($bodyVal, true);
                    if (is_array($decoded)) {
                        $fields = array_merge($fields, $decoded);
                    }
                }
            } elseif (str_starts_with($arg, '--json=')) {
                $jsonStr = substr($arg, 7);
                $decoded = json_decode($jsonStr, true);
                if (is_array($decoded)) {
                    $fields = array_merge($fields, $decoded);
                }
            } elseif (str_contains($arg, '=') && !str_starts_with($arg, '--')) {
                $parts = explode('=', $arg, 2);
                $fields[$parts[0]] = $parts[1];
            }
        }

        if (in_array('--cors', $args, true)) {
            return $this->testCors($method, $path);
        }

        if (in_array('--webhook', $args, true)) {
            return $this->listenWebhook($args);
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $fields === [] && $collectionSave === null) {
            $this->write("Enter fields for {$method} {$path} (leave field name empty to finish):");
            $this->write('');
            do {
                $key = $this->ask('  Field name: ');
                if ($key === '') {
                    break;
                }
                $value = $this->ask('  Value: ');
                $fields[$key] = $value;
                $this->write('');
            } while (true);
        }

        // Auto-login before making the request
        if ($login) {
            $loginEmail = $fields['email'] ?? ($as !== null ? $as . '@test.com' : '');
            $loginPassword = $fields['password'] ?? 'password';
            $loginRole = $as ?? 'default';

            $this->write("  \033[33mLogging in as '{$loginRole}'...\033[0m");
            $loginCode = $this->sendInternal('POST', '/api/auth/login',
                ['email' => $loginEmail, 'password' => $loginPassword],
                [], 'json', $loginRole
            );

            if ($loginCode !== 0) {
                $this->write("  \033[31mLogin failed. Check credentials.\033[0m");
                return 1;
            }
            $this->write("  \033[32mLogin successful. Token saved.\033[0m");
            $this->write('');
            $as = $loginRole;
        }

        // Handle --as=guest (no auth)
        if ($as === 'guest') {
            $as = null;
            $this->write("  \033[33mGuest mode: no auth token\033[0m");
        }

        // Handle --as=user:123 (specific user ID for login)
        if ($as !== null && str_contains($as, ':')) {
            $parts = explode(':', $as, 2);
            $as = $parts[0];
            $fields['user_id'] = $parts[1];
        }

        $statusCode = 0;
        $totalMs = 0;

        if ($loop > 1) {
            $this->write("  \033[33mRunning {$loop} requests...\033[0m");
            $this->write('');
        }

        for ($i = 0; $i < $loop; $i++) {
            $start = microtime(true);
            $statusCode = $this->sendInternal($method, $path, $fields, $customHeaders, $contentType, $as);
            $elapsed = (microtime(true) - $start) * 1000;
            $totalMs += $elapsed;

            if ($loop > 1) {
                $mark = $statusCode < 400 ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
                $this->write("    {$mark} Request " . ($i + 1) . "/{$loop}: {$statusCode} (" . round($elapsed, 1) . "ms)");
            }
        }

        if ($loop > 1) {
            $avgMs = $totalMs / $loop;
            $this->write('');
            $this->write("  \033[33mResults: {$loop} requests, avg " . round($avgMs, 1) . "ms, total " . round($totalMs / 1000, 2) . "s\033[0m");
        }

        if ($collectionSave !== null) {
            $this->saveToCollection($collectionSave, $method, $path, $fields, $customHeaders, $contentType, $as);
            $this->write("  \033[32m✓ Saved to collection '{$collectionSave}'.\033[0m");
        }

        if ($watch) {
            $this->watchMode($method, $path, $fields, $customHeaders, $contentType, $as);
        }

        return $statusCode < 400 ? 0 : 1;
    }

    // ─── Watch Mode ────────────────────────────────

    private function watchMode(
        string $method,
        string $path,
        array $fields,
        array $customHeaders,
        string $contentType,
        ?string $as
    ): void {
        $watchDirs = [
            $this->basePath . DIRECTORY_SEPARATOR . 'app',
            $this->basePath . DIRECTORY_SEPARATOR . 'routes',
        ];

        $watched = [];
        foreach ($watchDirs as $dir) {
            if (is_dir($dir)) {
                $this->addFilesRecursive($dir, $watched);
            }
        }

        $this->write("  \033[33mWatching for changes... (Ctrl+C to stop)\033[0m");
        $this->write('');

        while (true) {
            sleep(1);
            $changed = false;
            foreach ($watched as $file => $mtime) {
                if (!is_file($file)) {
                    $changed = true;
                    unset($watched[$file]);
                    continue;
                }
                $newMtime = filemtime($file);
                if ($newMtime !== $mtime) {
                    $watched[$file] = $newMtime;
                    $changed = true;
                }
            }
            if ($changed) {
                $this->write("  \033[33mChange detected, re-running...\033[0m");
                $this->sendInternal($method, $path, $fields, $customHeaders, $contentType, $as);
                $this->write("  \033[33mWatching for changes... (Ctrl+C to stop)\033[0m");
                $this->write('');
            }
        }
    }

    private function addFilesRecursive(string $dir, array &$files): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->addFilesRecursive($path, $files);
            } elseif (str_ends_with($item, '.php')) {
                $files[$path] = filemtime($path);
            }
        }
    }

    // ─── Collection ────────────────────────────────

    private function saveToCollection(
        string $name,
        string $method,
        string $path,
        array $fields,
        array $headers,
        string $contentType,
        ?string $as
    ): void {
        $collections = $this->loadCollections();
        if (!isset($collections[$name])) {
            $collections[$name] = ['name' => $name, 'requests' => []];
        }
        $collections[$name]['requests'][] = [
            'method' => $method,
            'path' => $path,
            'fields' => $fields,
            'headers' => $headers,
            'content_type' => $contentType,
            'as' => $as,
        ];
        $dir = dirname($this->collectionFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->collectionFile, json_encode($collections, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function runCollection(string $name): int
    {
        $collections = $this->loadCollections();
        $col = $collections[$name] ?? null;
        if ($col === null) {
            $this->write("Collection '{$name}' not found.");
            $this->write('Use --collection-list to see available collections.');
            return 1;
        }

        $this->write("Running collection: \033[1;33m{$name}\033[0m");
        $this->write('');

        $passed = 0;
        $failed = 0;
        foreach ($col['requests'] as $i => $req) {
            $label = "{$req['method']} {$req['path']}";
            $this->write("  \033[90m[" . ($i + 1) . '/' . count($col['requests']) . "]\033[0m {$label}");
            $code = $this->sendInternal(
                $req['method'],
                $req['path'],
                $req['fields'] ?? [],
                $req['headers'] ?? [],
                $req['content_type'] ?? 'json',
                $req['as'] ?? null,
            );
            if ($code === 0) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $this->write('');
        $this->write("  Collection '{$name}' done: {$passed} passed, {$failed} failed");
        return $failed > 0 ? 1 : 0;
    }

    private function listCollections(): int
    {
        $collections = $this->loadCollections();
        if ($collections === []) {
            $this->write('No collections saved.');
            $this->write('Save one: php siro api:test POST /api/auth/login email=... --collection-save=myapi');
            return 0;
        }

        $this->table(
            ['Name', 'Requests', 'Last updated'],
            array_map(fn ($c) => [
                $c['name'],
                (string) count($c['requests']),
                $c['requests'] !== [] ? ($c['requests'][count($c['requests']) - 1]['method'] . ' ' . $c['requests'][count($c['requests']) - 1]['path']) : '-',
            ], array_values($collections))
        );
        $this->write('');
        $this->write('Run: php siro api:test --collection=<name>');
        return 0;
    }

    private function loadCollections(): array
    {
        if (!is_file($this->collectionFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->collectionFile), true);
        return is_array($data) ? $data : [];
    }

    // ─── Core logic (unchanged) ────────────────────

    private function sendInternal(
        string $method,
        string $path,
        array $fields,
        array $customHeaders,
        string $contentType,
        ?string $as
    ): int {
        $query = [];
        $pathOnly = $path;
        $queryPos = strpos($path, '?');
        if ($queryPos !== false) {
            parse_str(substr($path, $queryPos + 1), $query);
            $pathOnly = substr($path, 0, $queryPos);
        }

        $token = null;
        if ($as !== null) {
            $token = $this->getToken($as);
            if ($token === null) {
                $this->write("Warning: No saved token for role '{$as}'.");
                $this->write("  Login first: php siro api:test POST /auth/login email={$as}@test.com password=... --as={$as}");
            }
        }

        $parsedHeaders = [];
        foreach ($customHeaders as $h) {
            $parts = explode(':', $h, 2);
            $parsedHeaders[strtolower(trim($parts[0]))] = trim($parts[1] ?? '');
        }

        if ($token !== null) {
            $parsedHeaders['authorization'] = 'Bearer ' . $token;
        }

        $parsedHeaders['accept'] = 'application/json';
        $parsedHeaders['content-type'] ??= 'application/json';
        $parsedHeaders['host'] = 'localhost';

        if ($contentType === 'form') {
            $parsedHeaders['content-type'] = 'application/x-www-form-urlencoded';
        }

        $bodyData = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? $fields : [];
        $start = microtime(true);

        try {
            ob_start();

            Router::setMiddlewareAliases([
                'auth' => \App\Middleware\AuthMiddleware::class,
                'throttle' => \App\Middleware\ThrottleMiddleware::class,
                'cors' => \App\Middleware\CorsMiddleware::class,
                'json' => \App\Middleware\JsonMiddleware::class,
            ]);

            $app = new App($this->basePath);
            $app->boot();
            $app->loadRoutes($this->basePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');

            Response::enableDebug(true);

            $request = new Request($method, $pathOnly, $query, $parsedHeaders, $bodyData, '127.0.0.1');

            $locale = $parsedHeaders['x-locale'] ?? '';
            if ($locale !== '' && preg_match('/^[a-z]{2}$/i', $locale)) {
                Lang::setLocale(strtolower($locale));
            } else {
                $acceptLang = $parsedHeaders['accept-language'] ?? '';
                if ($acceptLang !== '' && preg_match('/^([a-z]+)/i', $acceptLang, $m)) {
                    $langDir = Lang::basePath() . DIRECTORY_SEPARATOR . strtolower($m[1]);
                    if (is_dir($langDir)) {
                        Lang::setLocale(strtolower($m[1]));
                    }
                }
            }

            try {
                $response = $app->router->dispatch($request);
            } catch (ValidationException $e) {
                $response = $e->toResponse();
            }

            $statusCode = $response->statusCode();
            $duration = (microtime(true) - $start) * 1000;
            $memory = memory_get_peak_usage(true) / 1024 / 1024;

            $response->send();
            $body = ob_get_clean() ?: '';

            $this->write('');
            $this->write("  \033[1;33m{$method} {$pathOnly}\033[0m");

            $color = $statusCode < 300 ? '32' : ($statusCode < 400 ? '33' : '31');
            $this->write("  \033[{$color}mStatus: {$statusCode}\033[0m");
            $this->write("  Time:   " . number_format($duration, 1) . "ms");
            $this->write("  Memory: " . number_format($memory, 1) . "MB");

            if ($body !== '') {
                $this->write('');
                $this->write("  \033[1;90mBody:\033[0m");
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $this->write(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                } else {
                    $this->write(trim($body));
                }
            }

            $this->write('');

            if ($as !== null && $statusCode < 300) {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $t = $decoded['data']['token']
                       ?? $decoded['data']['access_token']
                       ?? $decoded['token']
                       ?? $decoded['access_token']
                       ?? null;

                    if (is_string($t) && strlen($t) >= 10) {
                        $tokens = $this->loadTokens();
                        $tokens[$as] = $t;
                        $dir = dirname($this->authFile);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }
                        file_put_contents($this->authFile, json_encode($tokens, JSON_PRETTY_PRINT));
                        $this->write("");
                        $this->write("  \033[32m✓ Token for '{$as}' saved to storage/api-test-auth.json\033[0m");
                    }
                }
            }

            $this->saveHistory($method, $pathOnly, $fields, $customHeaders, $statusCode, $duration, $memory, $as);

        } catch (\Throwable $e) {
            ob_end_clean();
            $duration = (microtime(true) - $start) * 1000;
            $memory = memory_get_peak_usage(true) / 1024 / 1024;

            $this->write('');
            $this->write("  \033[1;33m{$method} {$pathOnly}\033[0m");
            $this->write("  \033[31mError: " . $e->getMessage() . "\033[0m");
            $this->write("  File:  " . $e->getFile() . ":" . $e->getLine());
            $this->write("  Time:  " . number_format($duration, 1) . "ms");
            $this->write("  Memory: " . number_format($memory, 1) . "MB");
            $this->write('');

            $this->saveHistory($method, $pathOnly, $fields, $customHeaders, 500, $duration, $memory, $as);
            return 1;
        }

        return $statusCode < 400 ? 0 : 1;
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
        float $memory,
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
            'memory_mb' => round($memory, 1),
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

        $this->table(
            ['#', 'Time', 'Method', 'Path', 'Status', 'Time', 'Mem', 'As'],
            array_map(fn ($i, $e) => [
                (string) ($i + 1),
                $e['time'] ?? '?',
                $e['method'],
                $e['path'],
                (string) ($e['status'] ?? '?'),
                ($e['duration_ms'] ?? '?') . 'ms',
                ($e['memory_mb'] ?? '?') . 'MB',
                $e['as'] ?? '-',
            ], array_keys($history), $history)
        );
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

    // ─── CORS Test ───────────────────────────────

    private function testCors(string $method, string $path): int
    {
        $this->write("  \033[1;33mCORS Test: {$method} {$path}\033[0m");
        $this->write('');

        // Test 1: Preflight OPTIONS
        $this->write("  \033[1;90m[1/3] OPTIONS preflight request...\033[0m");
        $opts = [
            'method' => 'OPTIONS', 'path' => $path,
            'fields' => [], 'headers' => ['origin' => 'http://localhost:3000', 'access-control-request-method' => $method],
            'content_type' => 'json', 'as' => null,
        ];
        $code1 = $this->sendInternal($opts['method'], $opts['path'], $opts['fields'], ['Origin: http://localhost:3000', 'Access-Control-Request-Method: ' . $method], $opts['content_type'], $opts['as']);

        // Test 2: Request with Origin
        $this->write("  \033[1;90m[2/3] Request with Origin header...\033[0m");
        $code2 = $this->sendInternal($method, $path, [], ['Origin: http://localhost:3000'], 'json', null);

        // Test 3: Request without Origin
        $this->write("  \033[1;90m[3/3] Request without Origin...\033[0m");
        $code3 = $this->sendInternal($method, $path, [], [], 'json', null);

        $this->write('');
        $this->write('  CORS Test Results:');
        $this->write("    Preflight (OPTIONS):  {$code1}");
        $this->write("    With Origin:          {$code2}");
        $this->write("    Without Origin:       {$code3}");
        $this->write('');
        $this->write('  Expected: OPTIONS → 204, others → <status>');

        return 0;
    }

    // ─── Webhook Listener ─────────────────────────

    private function listenWebhook(array $args): int
    {
        $port = 9000;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $port = max(1, (int) substr($arg, 7));
            }
        }

        $this->write("  \033[1;33mWebhook listener on http://localhost:{$port}/webhook\033[0m");
        $this->write("  \033[90mPress Ctrl+C to stop\033[0m");
        $this->write('');

        $socket = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);
        if ($socket === false) {
            $this->write("  \033[31mError: Cannot bind to port {$port} ({$errstr})\033[0m");
            return 1;
        }

        $count = 0;
        while (true) {
            $conn = @stream_socket_accept($socket, 5);
            if ($conn === false) {
                continue;
            }

            $data = fread($conn, 65536);
            if ($data === false || $data === '') {
                fclose($conn);
                continue;
            }

            // Parse HTTP request
            $lines = explode("\r\n", $data);
            $requestLine = $lines[0] ?? '';
            preg_match('/^(\w+) (\S+)/', $requestLine, $m);
            $method = $m[1] ?? 'GET';
            $uri = $m[2] ?? '/';

            // Parse headers
            $headers = [];
            $i = 1;
            while ($i < count($lines) && $lines[$i] !== '') {
                if (str_contains($lines[$i], ':')) {
                    [$k, $v] = explode(':', $lines[$i], 2);
                    $headers[trim($k)] = trim($v);
                }
                $i++;
            }

            // Parse body
            $body = implode("\r\n", array_slice($lines, $i + 1));
            $bodyDecoded = json_decode($body, true);

            $count++;
            $this->write("  \033[32m[#{$count}] Received {$method} {$uri}\033[0m");
            foreach ($headers as $k => $v) {
                if (strtolower($k) === 'user-agent' || strtolower($k) === 'content-type') {
                    $this->write("    {$k}: {$v}");
                }
            }
            if (is_array($bodyDecoded)) {
                $this->write('    Body:');
                $this->write('      ' . json_encode($bodyDecoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } elseif ($body !== '') {
                $this->write('    Body: ' . mb_substr($body, 0, 500));
            }
            $this->write('');

            // Send 200 response
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{\"received\":true}");
            fclose($conn);
        }
    }

    private function printHelp(): void
    {
        $this->write('Usage: php siro api:test <method> <path> [field:value...] [options]');
        $this->write('Options:');
        $this->write('  --json              Send as JSON (default)');
        $this->write('  --form              Send as form-urlencoded');
        $this->write('  --header="X: v"     Custom header');
        $this->write('  --as=<role>         Auth as role (admin, user)');
        $this->write('  --watch             Watch files + auto re-run on change');
        $this->write('  --collection-save=N  Save request to named collection');
        $this->write('  --collection=N       Run all requests in collection');
        $this->write('  --collection-list    List saved collections');
        $this->write('  --history           View request history');
        $this->write('  --history=N         Show last N requests');
        $this->write('  --history-clear     Clear history');
        $this->write('');
        $this->write('Examples:');
        $this->write('  php siro api:test GET /api/users');
        $this->write('  php siro api:test POST /auth/login email=admin@test.com password=123456');
        $this->write('  php siro api:test GET /users --as=admin');
        $this->write('  php siro api:test POST /users name=John --collection-save=myapi');
        $this->write('  php siro api:test --collection=myapi');
        $this->write('  php siro api:test GET /users --watch');
    }
}
