<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siro\Core\Config;
use Siro\Core\Console;
use Siro\Core\Env;

/**
 * Coordinator-level regression tests for the CLI smoke-fix merge
 * (27/11/2026): the registry usage strings applied at merge time
 * (Console.php, from the lane REPORTs), the SYS-M7 api:why own-cost
 * display, and the serve/frankenphp:serve port-range validation.
 */
final class SmokeFixMergeTest extends TestCase
{
    private Console $console;
    private string $tempDir;

    protected function setUp(): void
    {
        Env::reset();
        Config::reset();

        $this->tempDir = sys_get_temp_dir() . '/siro_smoke_fix_merge_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir . '/storage/framework', 0777, true);
        @mkdir($this->tempDir . '/storage/logs/traces', 0777, true);

        $this->console = new Console($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
        Env::reset();
        Config::reset();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param array<int, string> $argv @return array{int, string} */
    private function runConsole(array $argv): array
    {
        ob_start();
        $exit = $this->console->run($argv);
        $output = (string) ob_get_clean();
        return [$exit, $output];
    }

    // ==================== Registry usage strings (merge-time) ====================

    /** @return array<string, array{string, string}> */
    public static function usageProvider(): array
    {
        return [
            'migrate --force-record' => ['migrate', '[--force-record]'],
            'migrate:fresh --force' => ['migrate:fresh', '[--seed] [--force]'],
            'db:seed [class]' => ['db:seed', '[class]'],
            'make:postman flags' => ['make:postman',
                '[--output=] [--host=] [--tag=TAG] [--method=] [--path=] [--flow=auth|crud]'],
            'make:test --unit' => ['make:test', '[--unit]'],
            'test:run flags' => ['test:run', '[--filter=name]'],
            'api:test --login' => ['api:test', '[--login]'],
            'api:test --collection-save' => ['api:test', '[--collection-save=N]'],
            'queue:work --tries' => ['queue:work', '[--sleep=N] [--tries=N]'],
            'queue:flush --yes' => ['queue:flush', '[--yes]'],
            'queue:status --failed' => ['queue:status', '[--failed=N]'],
            'serve --host' => ['serve', '[--host=<host>] [--port=8080]'],
            'frankenphp:serve --docker-port' => ['frankenphp:serve', '[--docker-port=<port>]'],
            'live --host' => ['live', '[--host=<host>] [--port=9090]'],
            'benchmark --warmup' => ['benchmark', '[--warmup=N]'],
            'replay flags' => ['replay', '[--format=]'],
            'db --mysql' => ['db', '[--mysql] [--mysql-official]'],
            'webhook:test --allow-private-ip' => ['webhook:test', '[--allow-private-ip]'],
            'log:export --status/--postman' => ['log:export', '[--status=] [--method=] [--slow] [--postman]'],
        ];
    }

    #[Test]
    #[DataProvider('usageProvider')]
    public function registryUsageListsParsedFlags(string $command, string $fragment): void
    {
        [$exit, $output] = $this->runConsole(['siro', $command, '--help']);
        $this->assertSame(0, $exit, $command . ' --help should exit 0');
        $this->assertStringContainsString($fragment, $output, $command . ' usage must list ' . $fragment);
    }

    // ==================== SYS-M7: api:why own-cost ====================

    #[Test]
    public function apiWhyFlagsOnlyGenuinelySlowMiddleware(): void
    {
        // Inclusive times, recorded inner-first (completion order): every entry
        // is >100ms inclusive, but only the handler itself is slow on own cost.
        $trace = [
            'method' => 'POST',
            'path' => '/api/orders',
            'status' => 200,
            'time_ms' => 555.0,
            'middleware' => [
                ['name' => 'handler', 'passed' => true, 'time_ms' => 550.0],
                ['name' => 'AuthMiddleware', 'passed' => true, 'time_ms' => 551.0],
                ['name' => 'CorsMiddleware', 'passed' => true, 'time_ms' => 554.0],
            ],
            'queries' => [],
        ];
        file_put_contents(
            $this->tempDir . '/storage/logs/traces/trace_why.json',
            json_encode($trace)
        );

        [$exit, $output] = $this->runConsole(['siro', 'api:why', 'POST', '/api/orders']);
        $this->assertSame(0, $exit, 'api:why should exit 0 with a matching trace');
        // Own costs: handler 550.0ms (slow), Auth 1.0ms, Cors 3.0ms — exactly one slow flag.
        $this->assertSame(
            1,
            substr_count($output, '⚠ slow'),
            'only the handler row should be flagged slow, not every middleware'
        );
        $this->assertStringContainsString(
            '1.0ms',
            $output,
            'AuthMiddleware own cost must be shown, not its inclusive time'
        );
        $this->assertStringContainsString(
            '3.0ms',
            $output,
            'CorsMiddleware own cost must be shown, not its inclusive time'
        );
    }

    // ==================== MAKE-M3: make:postman --tag substring ====================

    #[Test]
    public function postmanTagFilterUsesSubstringLikeOpenapi(): void
    {
        // MAKE-M3: --tag=products used to return an empty collection (exact
        // match) while make:openapi --tag=products matched by substring.
        // Both commands must now share substring semantics.
        foreach (['routes', 'docs', 'config'] as $dir) {
            @mkdir($this->tempDir . '/' . $dir, 0777, true);
        }
        file_put_contents($this->tempDir . '/.env', "APP_ENV=testing\nAPP_DEBUG=true\nDB_CONNECTION=sqlite\n");
        file_put_contents(
            $this->tempDir . '/config/database.php',
            '<?php return ["driver" => "sqlite", "database" => ":memory:"];'
        );
        file_put_contents($this->tempDir . '/routes/api.php', <<<'PHP'
<?php

declare(strict_types=1);

$router->get('/api/products', [\App\Controllers\ProductController::class, 'index']);
$router->post('/api/products', [\App\Controllers\ProductController::class, 'store']);
$router->get('/api/products/{id}', [\App\Controllers\ProductController::class, 'show']);
$router->get('/api/users', [\App\Controllers\UserController::class, 'index']);
PHP);
        $collectionFile = $this->tempDir . '/docs/postman/collection.json';

        [$exit, $output] = $this->runConsole(['siro', 'make:postman', '--tag=products']);
        $this->assertSame(0, $exit, 'make:postman --tag=products should exit 0: ' . $output);
        $collection = json_decode((string) file_get_contents($collectionFile), true);
        $this->assertIsArray($collection, 'collection must be valid JSON');
        $names = [];
        foreach ($collection['item'] ?? [] as $folder) {
            foreach ($folder['item'] ?? [] as $item) {
                $names[] = (string) ($item['name'] ?? '');
            }
        }
        $this->assertCount(3, $names, '--tag=products must match the 3 Product routes, got: ' . implode(', ', $names));
    }

    // ==================== Port-range validation ====================

    #[Test]
    public function serveRejectsOutOfRangePort(): void
    {
        [$exit, $output] = $this->runConsole(['siro', 'serve', '--port=99999']);
        $this->assertSame(1, $exit, 'serve --port=99999 must exit 1');
        $this->assertStringContainsString('Invalid port.', $output);
    }

    #[Test]
    public function frankenphpServeRejectsBadDockerPort(): void
    {
        [$exit, $output] = $this->runConsole(['siro', 'frankenphp:serve', '--docker', '--docker-port=abc']);
        $this->assertSame(1, $exit, 'frankenphp:serve --docker-port=abc must exit 1');
        $this->assertStringContainsString('Invalid docker port.', $output);

        [$exit2, $output2] = $this->runConsole(['siro', 'frankenphp:serve', '--docker', '--docker-port=70000']);
        $this->assertSame(1, $exit2, 'frankenphp:serve --docker-port=70000 must exit 1');
        $this->assertStringContainsString('Invalid docker port.', $output2);
    }
}
