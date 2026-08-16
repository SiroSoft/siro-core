<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\LogReplayCommand;

/**
 * LogReplayCommand tests — run() error paths, dry-run, seed mode, auth file
 * read/write encryption. These paths don't require a live server or curl.
 */
final class LogReplayTest extends TestCase
{
    private string $basePath;
    private string $tracesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/siro_replay_test_' . uniqid();
        $this->tracesDir = $this->basePath . '/storage/logs/traces';
        if (!is_dir($this->tracesDir)) {
            mkdir($this->tracesDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->basePath)) {
            $this->removeDir($this->basePath);
        }
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_dir($f)) {
                $this->removeDir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    private function writeTrace(string $name, array $data): string
    {
        $path = $this->tracesDir . '/' . $name . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $name;
    }

    private function makeCmd(): LogReplayCommand
    {
        return new LogReplayCommand($this->basePath);
    }

    /** @param array<int, string> $args @return array{int, string} */
    private function runCmd(array $args): array
    {
        ob_start();
        $exit = $this->makeCmd()->run($args);
        $output = ob_get_clean() ?: '';
        return [$exit, $output];
    }

    public function testRunWithoutTraceIdShowsUsage(): void
    {
        [$exit, $output] = $this->runCmd([]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testTraceNotFound(): void
    {
        [$exit, $output] = $this->runCmd(['nonexistent_trace']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Trace not found', $output);
    }

    public function testInvalidJsonTrace(): void
    {
        file_put_contents($this->tracesDir . '/bad.json', '{ not json');
        [$exit, $output] = $this->runCmd(['bad']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid trace', $output);
    }

    public function testMissingMethodField(): void
    {
        $this->writeTrace('nomethod', ['path' => '/api/x', 'status' => 200]);
        [$exit, $output] = $this->runCmd(['nomethod']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('missing required field: method', $output);
    }

    public function testInvalidMethod(): void
    {
        $this->writeTrace('badmethod', ['method' => 'TRACE', 'path' => '/api/x', 'status' => 200]);
        [$exit, $output] = $this->runCmd(['badmethod']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('invalid method', $output);
    }

    public function testMissingPathDefaultsToRoot(): void
    {
        $this->writeTrace('nopath', ['method' => 'GET', 'status' => 200]);
        // Missing path defaults to '/' and proceeds (no crash)
        [$exit, $output] = $this->runCmd(['nopath', '--dry-run']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry run', $output);
    }

    public function testInvalidHostRejected(): void
    {
        $this->writeTrace('evilhost', [
            'method' => 'GET', 'path' => '/api/x', 'status' => 200,
            'host' => 'evil.com\\@127.0.0.1',
        ]);
        [$exit, $output] = $this->runCmd(['evilhost']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing to replay', $output);
    }

    public function testDryRunGetReturnsZero(): void
    {
        $this->writeTrace('okget', [
            'method' => 'GET', 'path' => '/health/live', 'status' => 200,
            'host' => 'localhost:8080', 'request_body' => '',
        ]);
        [$exit, $output] = $this->runCmd(['okget', '--dry-run']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry run', $output);
    }

    public function testSeedModeReturnsZero(): void
    {
        $this->writeTrace('seedtrace', [
            'method' => 'POST', 'path' => '/api/products', 'status' => 201,
            'host' => 'localhost:8080',
            'request_body' => '{"name":"Widget","price":10}',
            'table' => 'products',
        ]);
        [$exit, $output] = $this->runCmd(['seedtrace', '--seed']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Seed command', $output);
    }

    public function testIsValidHostStatic(): void
    {
        $this->assertTrue(LogReplayCommand::isValidHost('localhost:8080'));
        $this->assertTrue(LogReplayCommand::isValidHost('api.example.com'));
        $this->assertFalse(LogReplayCommand::isValidHost('bad host'));
        $this->assertFalse(LogReplayCommand::isValidHost(''));
    }

    public function testIsValidPathStatic(): void
    {
        $this->assertTrue(LogReplayCommand::isValidPath('/api/users'));
        $this->assertFalse(LogReplayCommand::isValidPath("/api/x\n"));
    }

    public function testWriteReadAuthFileRoundtrip(): void
    {
        $authFile = $this->basePath . '/.siro_auth.json';
        $cmd = $this->makeCmd();

        // Set an APP_KEY so tokens get encrypted on write
        putenv('APP_KEY=test_app_key_for_replay_encryption_32chars!!');
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $write = $ref->getMethod('writeAuthFile');
        $write->setAccessible(true);
        $write->invoke($cmd, $authFile, ['email' => 'a@b.com', 'refresh_token' => 'rt_secret', 'access_token' => 'at_secret']);

        $raw = file_get_contents($authFile);
        $this->assertStringContainsString('enc:', $raw, 'tokens should be encrypted');

        $read = $ref->getMethod('readAuthFile');
        $read->setAccessible(true);
        $stored = $read->invoke($cmd, $authFile);
        $this->assertSame('rt_secret', $stored['refresh_token']);
        $this->assertSame('at_secret', $stored['access_token']);
        putenv('APP_KEY');
    }

    public function testReadAuthFileLegacyPlaintext(): void
    {
        $authFile = $this->basePath . '/.siro_auth.json';
        file_put_contents($authFile, json_encode(['email' => 'x@y.com', 'refresh_token' => 'plain_rt']));
        $cmd = $this->makeCmd();
        $ref = new \ReflectionClass(LogReplayCommand::class);
        $read = $ref->getMethod('readAuthFile');
        $read->setAccessible(true);
        $stored = $read->invoke($cmd, $authFile);
        $this->assertSame('plain_rt', $stored['refresh_token']);
    }
}
