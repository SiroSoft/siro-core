<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Logger;

final class LoggerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDir = sys_get_temp_dir() . '/siro_log_test_' . uniqid();
        mkdir($this->logDir, 0777, true);
        Logger::boot($this->logDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->logDir);
    }

    public function testBootCreatesLogDirectory(): void
    {
        $this->assertDirectoryExists($this->logDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs');
    }

    public function testBootCreatesAppLog(): void
    {
        $appLog = $this->logDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
        $this->assertFileExists($appLog);
    }

public function testSanitizeHeaders(): void
    {
        $headers = ['authorization' => 'Bearer token123', 'content-type' => 'application/json'];
        $sanitized = Logger::sanitizeHeaders($headers);
        $this->assertSame('[REDACTED]', $sanitized['authorization']);
        $this->assertSame('application/json', $sanitized['content-type']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
