<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\DbBackupCommand;
use Siro\Core\RuntimeManager;

/**
 * v1.4.2: Windows-only `2>NUL` shell redirects created a literal file named
 * NUL in the working directory on Linux. These tests lock the platform-aware
 * behaviour: on Linux every path must redirect to /dev/null, never NUL.
 *
 * Each test runs the code from a fresh temp cwd and asserts no literal NUL
 * file appears there — on Linux any surviving `2>NUL` fails the test.
 * (On Windows NUL is a device, never a file, so the tests pass trivially.)
 */
final class NullDeviceTest extends TestCase
{
    private string $cwd = '';
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cwd = getcwd() ?: '';
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_nul_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== '' && getcwd() !== $this->cwd) {
            chdir($this->cwd);
        }
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            $this->rmDir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testDetectExistingMySQLDoesNotCreateNulFile(): void
    {
        chdir($this->tmpDir);
        $mgr = new RuntimeManager();
        $result = $mgr->detectExistingMySQL();
        $this->assertTrue($result === null || is_int($result));
        $this->assertFileDoesNotExist($this->tmpDir . DIRECTORY_SEPARATOR . 'NUL');
    }

    public function testFindMysqldumpDoesNotCreateNulFile(): void
    {
        chdir($this->tmpDir);
        $cmd = new DbBackupCommand($this->tmpDir);
        $ref = new \ReflectionClass(DbBackupCommand::class);
        $method = $ref->getMethod('findMysqldump');
        $result = $method->invoke($cmd);
        $this->assertTrue($result === null || is_string($result));
        $this->assertFileDoesNotExist($this->tmpDir . DIRECTORY_SEPARATOR . 'NUL');
    }

    public function testAuditSupplyChainScriptDoesNotCreateNulFile(): void
    {
        $script = dirname(
            __DIR__,
            2
        ) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'audit-supply-chain.php';
        $this->assertFileExists($script);
        // Exit code is deliberately ignored: the point is the redirect, not
        // the audit result (composer may be unavailable in some environments).
        exec('cd ' . escapeshellarg($this
            ->tmpDir) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $this->assertFileDoesNotExist($this->tmpDir . DIRECTORY_SEPARATOR . 'NUL');
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
}
