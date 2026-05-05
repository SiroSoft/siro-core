<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogReplayCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $traceId = trim((string) ($args[0] ?? ''));
        $format = 'curl';
        $force = false;
        $overrides = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
            } elseif ($arg === '--force') {
                $force = true;
            } elseif ($arg === '--safe') {
                $force = false;
            } elseif (str_starts_with($arg, '--set=')) {
                $parts = explode('=', substr($arg, 6), 2);
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
            $this->write('');
            $this->write('Examples:');
            $this->write('  php siro log:replay siro_a1b2');
            $this->write('  php siro log:replay siro_a1b2 --force');
            $this->write('  php siro log:replay siro_a1b2 --set user_id=1');
            $this->write('  php siro log:replay siro_a1b2 --seed');
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

        // ─── Apply overrides ───
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
                $json = json_encode($seedData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $seedFile = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders' . DIRECTORY_SEPARATOR . 'ReplaySeeder.php';
                file_put_contents($seedFile, <<<PHP
<?php

declare(strict_types=1);

return new class {
    public function run(\$db): void
    {
        // Seeded from replay: {$path}
        \$data = {$json};
        // TODO: adjust table name and insert logic
        // \$db->exec("INSERT INTO ...");
    }
};

PHP);
                $this->write('  Generated: database/seeders/ReplaySeeder.php');
                $this->write('  Run: php siro db:seed ReplaySeeder');
                $this->write('');
            } else {
                $this->write('  Cannot seed: request body is not valid JSON.');
            }
        }

        // ─── Safe mode: warning for non-GET methods ───
        if ($method !== 'GET' && !$force) {
            $this->write('');
            $this->write("  \033[1;31m⚠  DANGER: Replaying {$method} {$path}\033[0m");
            $this->write('  This request has side effects (not a read-only GET).');
            $this->write('  Replaying it may:');
            $this->write('    • Charge user again (payment)');
            $this->write('    • Create duplicate records (insert)');
            $this->write('    • Modify data unexpectedly (update)');
            $this->write('    • Delete data permanently (delete)');
            $this->write('');
            $this->write('  To confirm, re-run with: --force');
            $this->write('  To see without executing, use: php siro log:export ' . $traceId . ' --postman');
            $this->write('');

            $answer = $this->ask('  \033[1;33mAre you sure you want to replay this? (yes/no): \033[0m');
            if (strtolower(trim($answer)) !== 'yes') {
                $this->write('  Replay cancelled.');
                return 1;
            }
            $this->write('');
        }

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
