<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Console;

/**
 * Regression tests for the CLI smoke-test log mismatches fixed 2026-11-27
 * (lane LOGS, base 975b068):
 *  S-12/LOG-M3 log:export <id> ignored the id for json/csv,
 *  LOG-M4     log:slow --min=N ignored the threshold in the slow.log fallback,
 *  LOG-M6     log:replay --dry-run <id> read the flag as the trace id,
 *  LOG-M7     log:tail --follow ignored SIGTERM (only SIGKILL worked),
 *  LOG-M5     log:replay --format=httpie --dry-run ignored the format,
 *  LOG-M2     log:top <arg> — locked in as MOOT: stray positional arg is ignored.
 */
class SmokeFixLogsTest extends TestCase
{
    private Console $console;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/siro_smoke_logs_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/storage/logs/traces', 0777, true);
        mkdir($this->tempDir . '/storage/logs/main', 0777, true);
        $monthDir = $this->tempDir . '/storage/logs/daily/' . date('Y-m');
        mkdir($monthDir, 0777, true);
        file_put_contents(
            $monthDir . '/request-' . date('Y-m-d') . '.log',
            "[2026-11-27 10:00:00] GET /api/users 200 15.20ms\n"
            . "[2026-11-27 10:01:00] GET /api/orders 500 250.00ms\n"
        );
        file_put_contents($this->tempDir . '/.env', "APP_ENV=testing\nAPP_DEBUG=true\n");

        $this->writeTrace('fx_get200', 'GET', '/api/products', 200, 12, '{}');
        $this->writeTrace('fx_post201', 'POST', '/api/products', 201, 45, '{"name":"Widget","price":9.99}');
        $this->writeTrace('fx_slow', 'GET', '/api/reports', 200, 850, '{}');
        file_put_contents(
            $this->tempDir . '/storage/logs/main/slow.log',
            "[2026-11-27 15:30:03] GET /api/reports 850.0ms slow\n"
        );

        $this->console = new Console($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    private function writeTrace(string $id, string $method, string $path, int $status, float $ms, string $body): void
    {
        file_put_contents($this->tempDir . '/storage/logs/traces/' . $id . '.json', json_encode([
            'trace_id' => $id,
            'timestamp' => '2026-11-27 10:00:00',
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'time_ms' => $ms,
            'ip' => '127.0.0.1',
            'host' => 'localhost:8080',
            'request_body' => $body,
        ]));
    }

    /** @param list<string> $argv @return array{0:int,1:string} */
    private function runCmd(array $argv): array
    {
        ob_start();
        $exit = $this->console->run($argv);
        $output = (string) ob_get_clean();
        return [$exit, $output];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $f
        ) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    // S-12 / LOG-M3: json export honours the trace id.
    public function testExportSingleTraceJson(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'log:export', 'fx_get200']);
        $this->assertSame(0, $exit);
        $this->assertSame(1, substr_count($output, '"trace_id"'), 'exactly one trace must be exported');
        $this->assertStringContainsString('/api/products', $output);
        $this->assertStringNotContainsString('/api/reports', $output);
        $this->assertStringNotContainsString('Widget', $output);
    }

    // S-12 / LOG-M3: csv export honours the trace id.
    public function testExportSingleTraceCsv(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'log:export', '--format=csv', 'fx_get200']);
        $this->assertSame(0, $exit);
        $lines = array_values(array_filter(explode("\n", trim($output)), fn (string $l): bool => $l !== ''));
        $this->assertCount(2, $lines, 'csv must be header + exactly one data row');
        $this->assertStringContainsString('/api/products', $lines[1]);
    }

    // S-12 / LOG-M3: no id keeps the old export-everything behaviour.
    public function testExportWithoutIdStillExportsAll(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'log:export']);
        $this->assertSame(0, $exit);
        $this->assertSame(3, substr_count($output, '"trace_id"'));
    }

    // S-12 / LOG-M3: unknown id is an error, not a silent full export.
    public function testExportMissingTraceId(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'log:export', 'no_such_trace']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Trace not found: no_such_trace', $output);
    }

    // S-12 / LOG-M3: --output writes only the requested trace.
    public function testExportSingleTraceToFile(): void
    {
        $out = $this->tempDir . '/export.json';
        [$exit, $output] = $this->runCmd(['siro', 'log:export', 'fx_slow', '--output=' . $out]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Exported 1 traces', $output);
        $decoded = json_decode((string) file_get_contents($out), true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('fx_slow', $decoded[0]['trace_id']);
    }

    // LOG-M4: slow.log fallback lines below --min must not be printed.
    public function testSlowMinFiltersFallbackSlowLog(): void
    {
        foreach (glob($this->tempDir . '/storage/logs/traces/*.json') ?: [] as $f) {
            unlink($f);
        }
        [$exit, $output] = $this->runCmd(['siro', 'log:slow', '--min=1000']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('> 1000ms', $output);
        $this->assertStringNotContainsString('850.0ms', $output);
        $this->assertStringContainsString('No slow requests found.', $output);
    }

    // LOG-M4: fallback keeps lines at/above --min.
    public function testSlowMinKeepsFallbackLineAboveThreshold(): void
    {
        foreach (glob($this->tempDir . '/storage/logs/traces/*.json') ?: [] as $f) {
            unlink($f);
        }
        file_put_contents(
            $this->tempDir . '/storage/logs/main/slow.log',
            "[2026-11-27 15:30:03] GET /api/reports 850.0ms slow\n"
            . "[2026-11-27 15:31:03] GET /api/heavy 1500.0ms slow\n"
        );
        [$exit, $output] = $this->runCmd(['siro', 'log:slow', '--min=1000']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('1500.0ms', $output);
        $this->assertStringNotContainsString('850.0ms', $output);
    }

    // LOG-M6: flags may precede the trace id.
    public function testReplayFlagBeforeTraceId(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'log:replay', '--dry-run', 'fx_get200']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString('http://localhost:8080/api/products', $output);
    }

    // LOG-M6: a space-separated --set value is not mistaken for the id.
    public function testReplayFlagBeforeTraceIdWithSet(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'log:replay', '--set', 'name=Gadget', '--dry-run', 'fx_post201']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Gadget', $output);
    }

    // LOG-M5: --format=httpie is honoured in dry-run mode.
    public function testReplayDryRunHttpieFormat(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'log:replay', 'fx_get200', '--dry-run', '--format=httpie']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('http http://localhost:8080/api/products', $output);
        $this->assertStringNotContainsString('curl -X GET', $output);
    }

    // LOG-M5: default dry-run still shows the curl command.
    public function testReplayDryRunDefaultFormatShowsCurl(): void
    {
        [$exit, $output] = $this->runCmd(['siro', 'log:replay', 'fx_get200', '--dry-run']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString("curl -X GET 'http://localhost:8080/api/products'", $output);
    }

    // LOG-M7: `timeout` (SIGTERM) must stop --follow; exit 124 proves the
    // TERM was honoured (137 would mean only SIGKILL via -k worked).
    public function testTailFollowStopsOnSigterm(): void
    {
        $runner = $this->tempDir . '/run-tail.php';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($runner, '<?php' . "\n"
            . 'declare(strict_types=1);' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . '$c = new \\Siro\\Core\\Console($argv[1]);' . "\n"
            . 'exit($c->run([\'siro\', \'log:tail\', \'--follow\']));' . "\n");
        $cmd = sprintf(
            'timeout -k 2 5 %s %s %s < /dev/null 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($runner),
            escapeshellarg($this->tempDir)
        );
        exec($cmd, $out, $code);
        $this->assertSame(124, $code, 'log:tail --follow must exit on SIGTERM (timeout exit 124), got ' . $code);
        $this->assertStringContainsString('Watching for new entries', implode("\n", $out));
    }

    // LOG-M2 (MOOT): stray positional arg is ignored — order matches default.
    public function testTopPositionalArgIgnored(): void
    {
        [, $default] = $this->runCmd(['siro', 'log:top']);
        [$exit, $output] = $this->runCmd(['siro', 'log:top', 'avg']);
        $this->assertSame(0, $exit);
        $this->assertSame($default, $output, 'log:top avg must behave exactly like log:top (arg ignored)');
    }
}
