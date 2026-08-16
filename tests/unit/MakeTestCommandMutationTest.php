<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\MakeTestCommand;
use Siro\Core\Env;

/**
 * Coverage tests for MakeTestCommand (unit/feature/from-trace generation).
 */
final class MakeTestCommandMutationTest extends TestCase
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
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_mt_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces', 0777, true);
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

    public function testUsageWithoutName(): void
    {
        $cmd = new MakeTestCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testMakeUnitTest(): void
    {
        $cmd = new MakeTestCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['Calculator', '--unit']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit' . DIRECTORY_SEPARATOR . 'CalculatorTest.php');
    }

    public function testMakeFeatureTest(): void
    {
        $cmd = new MakeTestCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['OrderController']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . 'OrderControllerTest.php');
    }

    public function testSanitizesName(): void
    {
        $cmd = new MakeTestCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['Bad Name!', '--unit']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit' . DIRECTORY_SEPARATOR . 'BadNameTest.php');
    }

    public function testFromTraceNotFound(): void
    {
        $cmd = new MakeTestCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--from-trace=nonexistent']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testFromTraceInvalid(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces' . DIRECTORY_SEPARATOR . 'bad-trace.json',
            'not-json'
        );
        $cmd = new MakeTestCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--from-trace=bad-trace']);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function testFromTraceGeneratesTest(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces' . DIRECTORY_SEPARATOR . 'trace-123.json',
            (string) json_encode([
                'method' => 'POST',
                'path' => '/api/users',
                'status' => 201,
                'request_body' => '{"name":"John"}',
                'response_body' => '{"success":true,"data":{"id":1,"name":"John"}}',
                'auth_header' => 'Bearer abc',
            ])
        );

        $cmd = new MakeTestCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--from-trace=trace-123', '--ignore=id,created_at']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . 'FromTracetrace_123Test.php');
    }

    public function testFromTraceGetNoAuth(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces' . DIRECTORY_SEPARATOR . 'get-ok.json',
            (string) json_encode([
                'method' => 'GET',
                'path' => '/health',
                'status' => 200,
                'response_body' => '{"success":true}',
            ])
        );

        $cmd = new MakeTestCommand($this->basePath);
        ob_start();
        $code = $cmd->run(['--from-trace=get-ok']);
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertFileExists($this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . 'FromTraceget_okTest.php');
    }
}
