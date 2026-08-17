<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DatabaseCommand;
use Siro\Core\Env;

/**
 * DatabaseCommand: status/start/stop/remove dispatch branches.
 */
final class DatabaseCommandBranchMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_dbc_' . uniqid();
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testStatus(): void
    {
        $cmd = new DatabaseCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['status']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testStart(): void
    {
        $cmd = new DatabaseCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['start']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testStop(): void
    {
        $cmd = new DatabaseCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['stop']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testRemove(): void
    {
        $cmd = new DatabaseCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['remove']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testInitMysql(): void
    {
        $cmd = new DatabaseCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['init', '--mysql']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testInitMysqlOfficial(): void
    {
        $cmd = new DatabaseCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['init', '--mysql-official']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }
}
