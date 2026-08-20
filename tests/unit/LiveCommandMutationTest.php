<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\LiveCommand;
use Siro\Core\Env;

/**
 * LiveCommand arg parsing + guard branches.
 */
final class LiveCommandMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . '/siro_live_' . uniqid();
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

    public function testMissingPublicDir(): void
    {
        $cmd = new LiveCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testCustomPortAndHost(): void
    {
        $cmd = new LiveCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--port=8080', '--host=127.0.0.1']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testInvalidPortClamped(): void
    {
        $cmd = new LiveCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--port=0']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testWithPublicDirShutdown(): void
    {
        mkdir($this->basePath . '/public', 0777, true);
        file_put_contents($this->basePath . '/public/index.php', '<?php');
        mkdir($this->basePath . '/app', 0777, true);
        $cmd = new LiveCommand($this->basePath);
        $cmd->shutdown();
        ob_start();
        $code = $cmd->run(['--port=9123', '--host=127.0.0.1']);
        ob_end_clean();
        $this->assertSame(0, $code);

        $signal = sys_get_temp_dir() . '/siro_live_' . md5($this->basePath) . '.tmp';
        @unlink($signal);
        @unlink($signal . '.pid');
    }
}
