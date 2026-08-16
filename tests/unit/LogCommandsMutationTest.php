<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\LogExportCommand;
use Siro\Core\Commands\LogStatsCommand;
use Siro\Core\Env;

/**
 * Coverage tests for LogStatsCommand and LogExportCommand using fake log files.
 */
final class LogCommandsMutationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_log_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'daily' . DIRECTORY_SEPARATOR . date('Y-m'), 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces', 0777, true);
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
        Cache::reset();
        putenv('APP_ENV');
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        parent::tearDown();
    }

    private function rmDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testLogStatsNoLogs(): void
    {
        $cmd = new LogStatsCommand($this->basePath);
        $code = $cmd->run([]);
        $this->assertSame(0, $code);
    }

    public function testLogStatsWithLogs(): void
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'daily' . DIRECTORY_SEPARATOR . date('Y-m');
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'request-001.log',
            "GET /api/users 200 45.2ms\nPOST /api/orders 201 150.7ms\nGET /api/error 500 300.1ms\n"
        );

        $cmd = new LogStatsCommand($this->basePath);
        $code = $cmd->run(['--days=7']);
        $this->assertSame(0, $code);
    }

    public function testLogStatsOldFileSkipped(): void
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'daily' . DIRECTORY_SEPARATOR . date('Y-m');
        $f = $dir . DIRECTORY_SEPARATOR . 'request-old.log';
        file_put_contents($f, "GET /old 200 10ms\n");
        touch($f, time() - 10 * 86400);

        $cmd = new LogStatsCommand($this->basePath);
        $code = $cmd->run(['--days=1']);
        $this->assertSame(0, $code);
    }

    public function testLogExportNoTracesDir(): void
    {
        $cmd = new LogExportCommand($this->basePath . DIRECTORY_SEPARATOR . 'nonexistent');
        $code = $cmd->run([]);
        $this->assertSame(1, $code);
    }

    public function testLogExportJsonWithFilters(): void
    {
        $traces = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        $t1 = [
            'id' => 'tr-1', 'method' => 'GET', 'path' => '/api/a', 'status' => 200,
            'time_ms' => 50.5, 'timestamp' => date('c'), 'ip' => '1.2.3.4',
        ];
        $t2 = [
            'id' => 'tr-2', 'method' => 'POST', 'path' => '/api/b', 'status' => 500,
            'time_ms' => 200.0, 'timestamp' => date('c'), 'ip' => '5.6.7.8',
        ];
        file_put_contents($traces . DIRECTORY_SEPARATOR . 'tr-1.json', (string) json_encode($t1));
        file_put_contents($traces . DIRECTORY_SEPARATOR . 'tr-2.json', (string) json_encode($t2));

        $cmd = new LogExportCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--format=json', '--method=GET']);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('/api/a', (string) $out);
        $this->assertStringNotContainsString('/api/b', (string) $out);
    }

    public function testLogExportCsvToFile(): void
    {
        $traces = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        file_put_contents($traces . DIRECTORY_SEPARATOR . 'tr-3.json', (string) json_encode([
            'id' => 'tr-3', 'method' => 'GET', 'path' => '/api/x', 'status' => 200, 'time_ms' => 5.0, 'ip' => '1.1.1.1',
        ]));

        $outFile = $this->basePath . DIRECTORY_SEPARATOR . 'export.csv';
        $cmd = new LogExportCommand($this->basePath);
        $code = $cmd->run(['--format=csv', '--output=' . $outFile]);
        $this->assertSame(0, $code);
        $this->assertFileExists($outFile);
        $csv = (string) file_get_contents($outFile);
        $this->assertStringContainsString('method', $csv);
        $this->assertStringContainsString('GET', $csv);
    }

    public function testLogExportNoMatches(): void
    {
        $cmd = new LogExportCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--status=999']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testLogExportUnsupportedFormat(): void
    {
        $traces = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        file_put_contents($traces . DIRECTORY_SEPARATOR . 'tr-4.json', (string) json_encode([
            'id' => 'tr-4', 'method' => 'GET', 'path' => '/', 'status' => 200, 'time_ms' => 1.0,
        ]));
        $cmd = new LogExportCommand($this->basePath);
        $code = $cmd->run(['--format=xml']);
        $this->assertSame(1, $code);
    }

    public function testLogExportPostmanMissingTrace(): void
    {
        $cmd = new LogExportCommand($this->basePath);
        $code = $cmd->run(['--postman']);
        $this->assertSame(1, $code);
    }

    public function testLogExportPostmanFull(): void
    {
        $traces = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        file_put_contents($traces . DIRECTORY_SEPARATOR . 'tr-5.json', (string) json_encode([
            'id' => 'tr-5', 'method' => 'POST', 'path' => '/api/login', 'status' => 200,
            'time_ms' => 10.0, 'host' => 'api.example.com',
            'request_headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer abc'],
            'auth_header' => 'Bearer xyz',
            'request_body' => '{"user":"a"}',
        ]));

        $cmd = new LogExportCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['tr-5', '--postman']);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('curl', (string) $out);
        $this->assertStringContainsString('POST', (string) $out);
        $this->assertStringContainsString('/api/login', (string) $out);
    }
}
