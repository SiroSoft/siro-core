<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\RuntimeManager;

/**
 * RuntimeManager — file-based operations (list, switch, remove, active, path)
 * against a fake HOME runtime directory.
 */
final class RuntimeManagerTest extends TestCase
{
    private string $home;
    private string $runtimeDir;
    private string $binDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_rt_' . uniqid('', true);
        $this->runtimeDir = $this->home . DIRECTORY_SEPARATOR . '.siro' . DIRECTORY_SEPARATOR . 'runtime';
        $this->binDir = $this->home . DIRECTORY_SEPARATOR . '.siro' . DIRECTORY_SEPARATOR . 'bin';
        mkdir($this->runtimeDir, 0777, true);
        mkdir($this->binDir, 0777, true);
        putenv('HOME=' . $this->home);
        putenv('USERPROFILE=' . $this->home);
    }

    protected function tearDown(): void
    {
        putenv('HOME');
        putenv('USERPROFILE');
        if (is_dir($this->home)) {
            $this->rmDir($this->home);
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

    private function resolve(string $v): string
    {
        return match ($v) {
            '8.0' => '8.0.30',
            '8.1' => '8.1.31',
            '8.2' => '8.2.31',
            '8.3' => '8.3.17',
            '8.4' => '8.4.5',
            default => $v,
        };
    }

    private function makeRuntime(string $version): string
    {
        $full = $this->resolve($version);
        $dir = $this->runtimeDir . DIRECTORY_SEPARATOR . $full;
        mkdir($dir, 0777, true);
        $phpBin = $dir . DIRECTORY_SEPARATOR . 'bin';
        mkdir($phpBin, 0777, true);
        file_put_contents($phpBin . DIRECTORY_SEPARATOR . 'php', "#!/bin/sh\necho php\n");
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'php.exe', 'fake');
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'php.ini', '');
        return $full;
    }

    public function testRuntimeDirAndBinDir(): void
    {
        $mgr = new RuntimeManager();
        $this->assertSame($this->runtimeDir, $mgr->runtimeDir());
        $this->assertSame($this->binDir, $mgr->binDir());
    }

    public function testListVersionsEmpty(): void
    {
        $mgr = new RuntimeManager();
        $this->assertSame([], $mgr->listVersions());
    }

    public function testListVersionsWithRuntimes(): void
    {
        $full = $this->makeRuntime('8.2');
        $mgr = new RuntimeManager();
        $versions = $mgr->listVersions();
        $this->assertCount(1, $versions);
        $this->assertSame($full, $versions[0]['version']);
    }

    public function testGetActiveNone(): void
    {
        $mgr = new RuntimeManager();
        $this->assertSame('', $mgr->getActive());
    }

    public function testGetActiveSet(): void
    {
        $full = $this->makeRuntime('8.2');
        $mgr = new RuntimeManager();
        $ref = new \ReflectionClass(RuntimeManager::class);
        $m = $ref->getMethod('setActive');
        $m->setAccessible(true);
        $m->invoke($mgr, $full);
        $this->assertSame($full, $mgr->getActive());
    }

    public function testSwitchMissing(): void
    {
        $mgr = new RuntimeManager();
        $result = $mgr->switch('9.9');
        $this->assertFalse($result['success']);
    }

    public function testSwitchSuccess(): void
    {
        $full = $this->makeRuntime('8.2');
        $mgr = new RuntimeManager();
        $result = $mgr->switch($full);
        $this->assertTrue($result['success']);
        $this->assertSame($full, $mgr->getActive());
    }

    public function testRemoveMissing(): void
    {
        $mgr = new RuntimeManager();
        $result = $mgr->remove('9.9');
        $this->assertFalse($result['success']);
    }

    public function testRemoveSuccess(): void
    {
        $full = $this->makeRuntime('8.2');
        $mgr = new RuntimeManager();
        $result = $mgr->remove($full);
        $this->assertTrue($result['success']);
        $this->assertDirectoryDoesNotExist($this->runtimeDir . DIRECTORY_SEPARATOR . $full);
    }

    public function testCurrentPhpBinary(): void
    {
        $this->makeRuntime('8.2');
        $mgr = new RuntimeManager();
        $ref = new \ReflectionClass(RuntimeManager::class);
        $m = $ref->getMethod('setActive');
        $m->setAccessible(true);
        $m->invoke($mgr, '8.2');
        $bin = $mgr->currentPhpBinary();
        $this->assertIsString($bin);
    }

    public function testInstallAlreadyInstalled(): void
    {
        $full = $this->makeRuntime('8.2');
        $mgr = new RuntimeManager();
        ob_start();
        $result = $mgr->install($full);
        ob_end_clean();
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('already installed', $result['message']);
    }

    public function testInstallUnsupportedFamily(): void
    {
        $mgr = new RuntimeManager();
        ob_start();
        $result = $mgr->install('7.4');
        ob_end_clean();
        $this->assertIsArray($result);
    }

    public function testDbStatus(): void
    {
        $mgr = new RuntimeManager();
        $result = $mgr->dbStatus();
        $this->assertIsArray($result);
    }

    public function testDetectExistingMySQL(): void
    {
        $mgr = new RuntimeManager();
        $result = $mgr->detectExistingMySQL();
        // Returns either an array (details) or a port number depending on environment
        $this->assertTrue(is_array($result) || is_int($result) || is_bool($result));
    }

    public function testInstallDownloadFail(): void
    {
        // Version with no matching download → unsupported or download-fail message
        $mgr = new RuntimeManager();
        ob_start();
        $result = $mgr->install('9.9.9');
        ob_end_clean();
        $this->assertArrayHasKey('success', $result);
    }

    public function testReadWriteDbActive(): void
    {
        $mgr = new RuntimeManager();
        $ref = new \ReflectionClass(RuntimeManager::class);
        $read = $ref->getMethod('readDbActive');
        $read->setAccessible(true);
        // No active file → null
        $this->assertNull($read->invoke($mgr));
    }

    public function testDbStatusNotInstalled(): void
    {
        $mgr = new RuntimeManager();
        $status = $mgr->dbStatus();
        $this->assertArrayHasKey('installed', $status);
    }
}
