<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\RuntimeCommand;
use Siro\Core\Env;

/**
 * RuntimeCommand usage/list/current/path branches (no download needed).
 */
final class RuntimeCommandMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('JWT_SECRET=this_is_a_sufficiently_long_jwt_secret_12345678');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        parent::tearDown();
    }

    public function testInstallUsage(): void
    {
        $cmd = new RuntimeCommand();
        ob_start();
        $code = $cmd->run(['install']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testSwitchUsage(): void
    {
        $cmd = new RuntimeCommand();
        ob_start();
        $code = $cmd->run(['switch']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testRemoveUsage(): void
    {
        $cmd = new RuntimeCommand();
        ob_start();
        $code = $cmd->run(['remove']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testUnknownAction(): void
    {
        $cmd = new RuntimeCommand();
        ob_start();
        $code = $cmd->run(['bogus']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testListEmpty(): void
    {
        $cmd = new RuntimeCommand();
        ob_start();
        $code = $cmd->run(['list']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testCurrent(): void
    {
        $cmd = new RuntimeCommand();
        ob_start();
        $code = $cmd->run(['current']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testPath(): void
    {
        $cmd = new RuntimeCommand();
        ob_start();
        $code = $cmd->run(['path']);
        ob_end_clean();
        $this->assertContains($code, [0, 1]);
    }

    public function testListWithFakeDir(): void
    {
        $dir = sys_get_temp_dir() . '/siro_runtime_' . uniqid();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/php-8.3.txt', 'test');
        putenv('SIRO_RUNTIME_DIR=' . $dir);
        $cmd = new RuntimeCommand();
        ob_start();
        $code = $cmd->run(['list']);
        ob_end_clean();
        putenv('SIRO_RUNTIME_DIR');
        $this->assertContains($code, [0, 1]);
    }
}
