<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Siro\Core\Console;
use Siro\Core\Logger;

class LogDebugCommandsTest extends TestCase
{
    private Console $console;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/siro_log_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/storage/logs/traces', 0777, true);
        mkdir($this->tempDir . '/storage/framework', 0777, true);
        
        // Create some fake log data (daily/monthly subdirectory for log commands)
        $logDir = $this->tempDir . '/storage/logs';
        $monthDir = $logDir . '/daily/' . date('Y-m');
        mkdir($monthDir, 0777, true);
        mkdir($logDir . '/main', 0777, true);
        file_put_contents($monthDir . '/error-' . date('Y-m-d') . '.log', 
            "[2026-05-13 10:00:00] RuntimeException: Test error in test.php:10\n" .
            "[2026-05-13 10:01:00] InvalidArgumentException: Bad request in api.php:50\n");
        file_put_contents($monthDir . '/request-' . date('Y-m-d') . '.log',
            "[2026-05-13 10:00:00] GET /api/users 200 15.20ms trace:abc123 ip:127.0.0.1\n");
        file_put_contents($monthDir . '/security-' . date('Y-m-d') . '.log',
            "[2026-05-13 10:00:00] [SECURITY] auth.failed {\"ip\":\"127.0.0.1\"}\n");
        file_put_contents($monthDir . '/slow-' . date('Y-m-d') . '.log',
            "[2026-05-13 10:00:00] GET /api/reports 500 2500.50ms (threshold: 100ms)\n");
        // SlowLogCommand reads from main/slow.log (format: METHOD /path STATUS TIMEms)
        file_put_contents($logDir . '/main/slow.log',
            "[2026-05-13 10:00:00] GET /api/reports 500 2500.50ms\n");
        
        // Create a trace file
        file_put_contents($logDir . '/traces/trace_001.json',
            '{"trace_id":"trace_001","timestamp":"2026-05-13 10:00:00","method":"GET","path":"/api/test"}');
        
        putenv('SIRO_BASE_PATH=' . $this->tempDir);
        Logger::boot($this->tempDir);
        $this->console = new Console($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
    }

    public function testDebugHealth(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'debug:health']);
        $output = ob_get_clean();
        $this->assertEquals(0, $exitCode, 'debug:health should exit 0');
        $this->assertStringContainsString('healthy', $output, 'Should indicate healthy');
    }

    public function testDebugLast(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'debug:last']);
        ob_get_clean();
        $this->assertEquals(0, $exitCode, 'debug:last should exit 0');
    }

    public function testLogTail(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'log:tail', '--lines=5']);
        $output = ob_get_clean();
        $this->assertEquals(0, $exitCode, 'log:tail should exit 0');
    }

    public function testLogStats(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'log:stats', '--days=1']);
        ob_get_clean();
        $this->assertEquals(0, $exitCode, 'log:stats should exit 0');
    }

    public function testLogSlow(): void
    {
        $slowFile = $this->tempDir . '/storage/logs/main/slow.log';
        $this->assertFileExists($slowFile, 'slow.log must exist');
        ob_start();
        $exitCode = $this->console->run(['siro', 'log:slow', '--limit=5']);
        $output = ob_get_clean();
        $this->assertEquals(0, $exitCode, 'log:slow should exit 0');
        $this->assertStringContainsString('2500.50ms', $output, 'Should show slow request');
    }

    public function testLogCleanupDryRun(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'log:cleanup', '--dry-run']);
        ob_get_clean();
        $this->assertEquals(0, $exitCode, 'log:cleanup --dry-run should exit 0');
    }

    public function testLogTop(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'log:top', '--limit=5']);
        $output = ob_get_clean();
        $this->assertEquals(0, $exitCode, 'log:top should exit 0');
    }

    public function testAliasWhy(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'why']);
        ob_get_clean();
        $this->assertEquals(0, $exitCode, 'alias "why" should resolve to debug:last');
    }

    public function testAliasSlow(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'slow', '--limit=3']);
        ob_get_clean();
        $this->assertEquals(0, $exitCode, 'alias "slow" should resolve to log:slow');
    }

    public function testAliasTraces(): void
    {
        ob_start();
        $exitCode = $this->console->run(['siro', 'traces', '--limit=5']);
        $output = ob_get_clean();
        $this->assertEquals(0, $exitCode, 'alias "traces" should resolve to trace:list');
        $this->assertStringContainsString('trace_001', $output, 'Should list traces');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getRealPath());
            else unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
