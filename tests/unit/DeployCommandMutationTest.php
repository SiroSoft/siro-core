<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\DeployCommand;
use Siro\Core\Env;

/**
 * Coverage tests for DeployCommand.
 */
final class DeployCommandMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_dep_' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
        Cache::reset();
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

    private function writeConfig(string $json): void
    {
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . 'deploy.json', $json);
    }

    public function testInitConfig(): void
    {
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--init']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'deploy.json');
    }

    public function testListEnvironments(): void
    {
        $this->writeConfig((string) json_encode([
            'default' => 'staging',
            'environments' => [
                'staging' => ['method' => 'git', 'remote' => 'origin', 'branch' => 'staging'],
                'production' => ['method' => 'rsync', 'host' => 'x', 'user' => 'u', 'target' => '/var/www'],
            ],
        ]));
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--list']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testListEmpty(): void
    {
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--list']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testEnvNotFound(): void
    {
        $this->writeConfig((string) json_encode(['default' => 'staging', 'environments' => ['staging' => []]]));
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['nonexistent']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testDeployDefaultEnv(): void
    {
        $this->writeConfig((string) json_encode([
            'default' => 'staging',
            'environments' => ['staging' => ['method' => 'custom', 'script' => 'echo hi']],
        ]));
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testDeployGitMethod(): void
    {
        $this->writeConfig((string) json_encode([
            'default' => 'staging',
            'environments' => ['staging' => ['method' => 'git', 'branch' => 'main']],
        ]));
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['staging']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testDeployRsyncMissingHost(): void
    {
        $this->writeConfig((string) json_encode([
            'default' => 'prod',
            'environments' => ['prod' => ['method' => 'rsync']],
        ]));
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['prod']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testDeployCustomMissingScript(): void
    {
        $this->writeConfig((string) json_encode([
            'default' => 'prod',
            'environments' => ['prod' => ['method' => 'custom']],
        ]));
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['prod']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testUnknownMethod(): void
    {
        $this->writeConfig((string) json_encode([
            'default' => 'prod',
            'environments' => ['prod' => ['method' => 'ftp']],
        ]));
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['prod']);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function testInvalidConfig(): void
    {
        $this->writeConfig('not-json');
        $cmd = new DeployCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['prod']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }
}
