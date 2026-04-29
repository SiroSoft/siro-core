<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\App;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Lang;
use Siro\Core\ValidationException;

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
            $this->printHelp();
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
        $customHeaders = [];
        $as = null;
        $contentType = 'json';

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
            } elseif (str_contains($arg, '=')) {
                $parts = explode('=', $arg, 2);
                $fields[$parts[0]] = $parts[1];
            }
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $fields === []) {
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

        return $this->sendInternal($method, $path, $fields, $customHeaders, $contentType, $as);
    }

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
                    $t = $decoded['data']['token'] ?? $decoded['token'] ?? null;
                    if (is_string($t) && strlen($t) >= 10) {
                        $tokens = $this->loadTokens();
                        $tokens[$as] = $t;
                        file_put_contents($this->authFile, json_encode($tokens, JSON_PRETTY_PRINT));
                        $this->write("  \033[32mToken for '{$as}' saved.\033[0m");
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

    private function printHelp(): void
    {
        $this->write('Usage: php siro api:test <method> <path> [field:value...] [options]');
        $this->write('Options:');
        $this->write('  --json              Send as JSON (default)');
        $this->write('  --form              Send as form-urlencoded');
        $this->write('  --header="X: v"     Custom header');
        $this->write('  --as=<role>         Auth as role (admin, user)');
        $this->write('  --history           View request history');
        $this->write('  --history=N          Show last N requests');
        $this->write('  --history-clear     Clear history');
        $this->write('');
        $this->write('Interactive mode:');
        $this->write('  POST/PUT/PATCH without fields will prompt for input');
        $this->write('');
        $this->write('Examples:');
        $this->write('  php siro api:test GET /api/users');
        $this->write('  php siro api:test POST /auth/login email=admin@test.com password=123456');
        $this->write('  php siro api:test GET /users --as=admin');
        $this->write('  php siro api:test POST /users name=John email=john@test.com --as=admin');
        $this->write('  php siro api:test --history');
    }
}
