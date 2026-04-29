<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class MakeTestCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''));
        if ($name === '') {
            $this->write('Test name is required. Example: php siro make:test UserApi');
            $this->write('Generates an integration test file in tests/ directory.');
            return 1;
        }

        $name = preg_replace('/[^a-zA-Z0-9_]+/', '_', $name) ?? $name;
        $name = trim($name, '_');
        if ($name === '') {
            $this->write('Invalid test name.');
            return 1;
        }

        $type = 'api';
        foreach ($args as $arg) {
            if ($arg === '--unit') {
                $type = 'unit';
            }
        }

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . $name . '_test.php';

        if (is_file($path)) {
            if (!$this->confirmOverwrite($this->basePath, $path)) {
                $this->write('Skipped: tests/' . $name . '_test.php');
                return 0;
            }
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        if ($type === 'unit') {
            file_put_contents($path, $this->unitTemplate($name));
        } else {
            file_put_contents($path, $this->apiTemplate($name));
        }

        $this->write('Generated: tests/' . $name . '_test.php');
        $this->write('  Run: php tests/' . $name . '_test.php');
        return 0;
    }

    private function apiTemplate(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Integration test for {$name}.
 *
 * Run: php tests/{$name}_test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Siro\Core\App;
use Siro\Core\Request;

\$basePath = dirname(__DIR__);

\$app = new App(\$basePath);
\$app->boot();
\$app->loadRoutes(\$basePath . '/routes/api.php');

echo "=== {$name} Test ===\n\n";

\$passed = 0;
\$failed = 0;

function test(string \$name, callable \$fn): void
{
    global \$passed, \$failed;
    try {
        \$fn();
        echo "  \\033[32m✓\\033[0m {\$name}\n";
        \$passed++;
    } catch (\Throwable \$e) {
        echo "  \\033[31m✗ {\$name}: {\$e->getMessage()}\\033[0m\n";
        echo "    File: {\$e->getFile()}:{\$e->getLine()}\n";
        \$failed++;
    }
}

function dispatch(string \$method, string \$path, array \$body = [], array \$headers = []): array
{
    global \$app;
    ob_start();
    try {
        \$request = new Request(\$method, \$path, [], \$headers, \$body, '127.0.0.1');
        \$response = \$app->router->dispatch(\$request);
        ob_end_clean();
        return [
            'status' => \$response->statusCode(),
            'body' => json_decode(json_encode(\$response->payload()), true),
        ];
    } catch (\Siro\Core\ValidationException \$e) {
        ob_end_clean();
        \$response = \$e->toResponse();
        return [
            'status' => \$response->statusCode(),
            'body' => json_decode(json_encode(\$response->payload()), true),
        ];
    } catch (\Throwable \$e) {
        ob_end_clean();
        throw \$e;
    }
}

// ─── Write your tests below ────────────────────────

test('TODO: write test name', function () {
    \$res = dispatch('GET', '/api/example');
    assert(\$res['status'] === 200, 'Expected 200, got ' . \$res['status']);
});

echo "\n=== Results ===\n";
echo "Passed: {\$passed}\n";
echo "Failed: {\$failed}\n";
exit(\$failed > 0 ? 1 : 0);

PHP;
    }

    private function unitTemplate(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Unit test for {$name}.
 *
 * Run: php tests/{$name}_test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== {$name} Unit Test ===\n\n";

\$passed = 0;
\$failed = 0;

function test(string \$name, callable \$fn): void
{
    global \$passed, \$failed;
    try {
        \$fn();
        echo "  \\033[32m✓\\033[0m {\$name}\n";
        \$passed++;
    } catch (\Throwable \$e) {
        echo "  \\033[31m✗ {\$name}: {\$e->getMessage()}\\033[0m\n";
        \$failed++;
    }
}

// ─── Write your tests below ────────────────────────

test('TODO: write test name', function () {
    \$result = 1 + 1;
    assert(\$result === 2, 'Expected 2, got ' . \$result);
});

echo "\n=== Results ===\n";
echo "Passed: {\$passed}\n";
echo "Failed: {\$failed}\n";
exit(\$failed > 0 ? 1 : 0);

PHP;
    }
}
