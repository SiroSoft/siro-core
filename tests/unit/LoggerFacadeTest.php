<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Logger;

/**
 * Logger facade tests — request/slowRequest/error/warning/debug/security logs
 * + sanitization (PII redaction).
 */
final class LoggerFacadeTest extends TestCase
{
    private string $logDir;
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/siro_log_test_' . uniqid();
        $this->logDir = $this->basePath . '/storage/logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
        putenv('LOG_RETENTION_DAYS=30');
        putenv('LOG_LEVEL=debug');
        Logger::boot($this->basePath);
    }

    protected function tearDown(): void
    {
        Logger::reset();
        if (is_dir($this->basePath)) {
            $this->removeDir($this->basePath);
        }
        putenv('LOG_RETENTION_DAYS');
        putenv('LOG_LEVEL');
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_dir($f)) {
                $this->removeDir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    private function dailyLog(string $type): string
    {
        return $this->logDir . '/daily/' . date('Y-m') . '/' . $type . '-' . date('Y-m-d') . '.log';
    }

    public function testRequestLogsToFile(): void
    {
        Logger::request('GET', '/api/x', 200, 1.5, '127.0.0.1', 'trace1', 'UA');
        $daily = $this->dailyLog('request');
        $this->assertFileExists($daily);
        $content = file_get_contents($daily);
        $this->assertStringContainsString('GET', $content);
        $this->assertStringContainsString('/api/x', $content);
    }

    public function testSlowRequestLogged(): void
    {
        Logger::slowRequest('POST', '/api/slow', 200, 500.0);
        $slow = $this->dailyLog('slow');
        $this->assertFileExists($slow);
        $this->assertStringContainsString('POST', (string) file_get_contents($slow));
    }

    public function testErrorLogged(): void
    {
        Logger::error(new \RuntimeException('boom'));
        $err = $this->dailyLog('error');
        $this->assertFileExists($err);
        $this->assertStringContainsString('boom', (string) file_get_contents($err));
    }

    public function testErrorAsStringLogged(): void
    {
        Logger::error('string error message');
        $err = $this->dailyLog('error');
        $this->assertStringContainsString('string error message', (string) file_get_contents($err));
    }

    public function testWarningLogged(): void
    {
        Logger::warning('careful here');
        $warn = $this->dailyLog('warning');
        $this->assertFileExists($warn);
        $this->assertStringContainsString('careful here', (string) file_get_contents($warn));
    }

    public function testDebugLogged(): void
    {
        Logger::debug('debug info');
        $dbg = $this->dailyLog('debug');
        $this->assertFileExists($dbg);
        $this->assertStringContainsString('debug info', (string) file_get_contents($dbg));
    }

    public function testSecurityLogged(): void
    {
        Logger::security('auth.failed', ['ip' => '1.2.3.4']);
        $sec = $this->dailyLog('security');
        $this->assertFileExists($sec);
        $this->assertStringContainsString('auth.failed', (string) file_get_contents($sec));
    }

    public function testSanitizeRedactsPassword(): void
    {
        $clean = Logger::sanitize('password=supersecret123 token=abc tokenxyz');
        $this->assertStringNotContainsString('supersecret123', $clean);
    }

    public function testSanitizeHeadersRedactsAuth(): void
    {
        $headers = Logger::sanitizeHeaders(['Authorization' => 'Bearer secret', 'X-Other' => 'ok']);
        $this->assertStringContainsString('REDACTED', $headers['Authorization']);
        $this->assertSame('ok', $headers['X-Other']);
    }

    public function testSanitizeJsonBodyRedactsPassword(): void
    {
        $clean = Logger::sanitizeJsonBody('{"password":"p123","email":"a@b.com"}');
        $this->assertStringContainsString('REDACTED', $clean);
        $this->assertStringContainsString('a@b.com', $clean);
    }

    public function testGetLogDir(): void
    {
        $dir = Logger::getLogDir();
        $this->assertStringContainsString('storage' . DIRECTORY_SEPARATOR . 'logs', $dir);
    }
}
