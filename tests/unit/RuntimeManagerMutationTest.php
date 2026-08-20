<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Env;
use Siro\Core\RuntimeManager;

/**
 * Coverage for RuntimeManager non-download paths.
 */
final class RuntimeManagerMutationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('APP_ENV=testing');
        $this->tmp = sys_get_temp_dir() . '/siro_rt_' . uniqid();
        mkdir($this->tmp, 0777, true);
        putenv('SIRO_RUNTIME_DIR=' . $this->tmp);
    }

    protected function tearDown(): void
    {
        putenv('SIRO_RUNTIME_DIR');
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        if (is_dir($this->tmp)) {
            system('rm -rf ' . escapeshellarg($this->tmp));
        }
        parent::tearDown();
    }

    public function testRuntimeDirAndBinDir(): void
    {
        $m = new RuntimeManager();
        $this->assertNotEmpty($m->runtimeDir());
        $this->assertNotEmpty($m->binDir());
    }

    public function testGetActiveDefault(): void
    {
        $m = new RuntimeManager();
        $this->assertIsString($m->getActive());
    }

    public function testCurrentPhpBinary(): void
    {
        $m = new RuntimeManager();
        $this->assertStringContainsString('php', $m->currentPhpBinary());
    }

    public function testListVersionsEmpty(): void
    {
        $m = new RuntimeManager();
        $this->assertIsArray($m->listVersions());
    }

    public function testSwitchNotInstalled(): void
    {
        $m = new RuntimeManager();
        $res = $m->switch('9.9.9');
        $this->assertFalse($res['success']);
    }

    public function testDetectExistingMySQL(): void
    {
        $m = new RuntimeManager();
        $res = $m->detectExistingMySQL();
        $this->assertIsInt($res);
    }

    public function testDbStatus(): void
    {
        $m = new RuntimeManager();
        $res = $m->dbStatus();
        $this->assertArrayHasKey('installed', $res);
        $this->assertArrayHasKey('running', $res);
    }

    public function testInstallMySQLDetectsExisting(): void
    {
        $m = new RuntimeManager();
        $res = $m->installMySQL();
        $this->assertArrayHasKey('success', $res);
    }
}
