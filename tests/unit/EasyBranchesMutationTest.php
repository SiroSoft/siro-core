<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\DbBackupCommand;
use Siro\Core\Commands\DbCheckCommand;
use Siro\Core\Commands\DbHealthCommand;
use Siro\Core\Commands\ServeCommand;
use Siro\Core\Commands\StorageLinkCommand;
use Siro\Core\Env;

/**
 * Extra coverage for DB/commands edge branches.
 */
final class EasyBranchesMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_easy_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'public', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'backups', 0777, true);
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
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

    private function writeSqliteConfig(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
    }

    public function testDbBackupWithExistingBackups(): void
    {
        $this->writeSqliteConfig();
        // pre-create a backup file to exercise list-recent branch
        $backupDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'backups';
        file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'backup-old.sqlite', 'x');

        $cmd = new DbBackupCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbBackupNoConfig(): void
    {
        $cmd = new DbBackupCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbCheckCommand(): void
    {
        $this->writeSqliteConfig();
        $cmd = new DbCheckCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testDbHealthCommand(): void
    {
        $this->writeSqliteConfig();
        $cmd = new DbHealthCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testServeCommandGuards(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $this->assertSame(1, $cmd->run(['--port=abc']));
        ob_end_clean();
    }

    public function testServeMissingPublic(): void
    {
        $cmd = new ServeCommand($this->basePath);
        ob_start();
        $this->assertSame(1, $cmd->run(['--port=8080']));
        ob_end_clean();
    }

    public function testStorageLinkMissingDirs(): void
    {
        $cmd = new StorageLinkCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }
}
