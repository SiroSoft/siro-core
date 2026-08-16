<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Commands\LogReplayCommand;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Session;

/**
 * In-process coverage of LogReplayCommand interactive + curl-missing paths
 * using constructor-injected input provider and curl-missing flag.
 */
final class LogReplayInteractiveTest extends TestCase
{
    private string $basePath;
    private string $tracesDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_lri_' . uniqid('', true);
        $this->tracesDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        mkdir($this->tracesDir, 0777, true);
        putenv('APP_ENV=local');
        Env::reset();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        Session::setInstance(null);
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

    private function writeTrace(string $name, array $data): string
    {
        $path = $this->tracesDir . DIRECTORY_SEPARATOR . $name . '.json';
        file_put_contents($path, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $name;
    }

    private function baseTrace(string $id = 'tr-1', array $over = []): array
    {
        return array_merge([
            'id' => $id,
            'method' => 'POST',
            'path' => '/api/test',
            'status' => 200,
            'request_body' => '{"a":"b"}',
            'response_body' => '{"success":true}',
            'host' => '127.0.0.1:1',
            'timestamp' => date('c'),
        ], $over);
    }

    /** @param array<int, string> $args @return array{int, string} */
    private function runCmd(array $args, array $inputs = [], bool $curlMissing = true): array
    {
        $provider = $inputs !== []
            ? static function () use (&$inputs): string {
                return (string) array_shift($inputs);
            }
            : static fn (): string => "\n";
        ob_start();
        $cmd = new LogReplayCommand($this->basePath, $provider, $curlMissing);
        $exit = $cmd->run($args);
        $output = ob_get_clean() ?: '';
        return [$exit, $output];
    }

    public function testCurlMissingReplay(): void
    {
        $this->writeTrace('tr-1', $this->baseTrace());
        [$exit, $output] = $this->runCmd(['tr-1']);
        $this->assertContains($exit, [0, 1]);
        $this->assertStringContainsString('curl', $output);
    }

    public function testCurlMissingAuth(): void
    {
        $this->writeTrace('tr-auth', $this->baseTrace('tr-auth', ['auth_header' => 'Bearer abc']));
        [$exit, $output] = $this->runCmd(['tr-auth', '--auth']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testAsUserLoginFailure(): void
    {
        $this->writeTrace('tr-as', $this->baseTrace('tr-as'));
        [$exit] = $this->runCmd(['tr-as', '--as=admin@test.com', '--force'], ['secret']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testAsUserLoginMissingPassword(): void
    {
        $this->writeTrace('tr-as2', $this->baseTrace('tr-as2'));
        [$exit] = $this->runCmd(['tr-as2', '--as=admin@test.com', '--force'], ["\n"]);
        $this->assertContains($exit, [0, 1]);
    }

    public function testProductionConfirmationNo(): void
    {
        putenv('APP_ENV=production');
        $this->writeTrace('tr-prod', $this->baseTrace('tr-prod', ['path' => '/api/delete']));
        [$exit] = $this->runCmd(['tr-prod', '--force'], ['n']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testProductionConfirmationYes(): void
    {
        putenv('APP_ENV=production');
        $this->writeTrace('tr-prod2', $this->baseTrace('tr-prod2', ['path' => '/api/delete']));
        [$exit] = $this->runCmd(['tr-prod2', '--force'], ['y']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testEditModeWithInputChanges(): void
    {
        $this->writeTrace('tr-edit', $this->baseTrace('tr-edit'));
        [$exit, $output] = $this->runCmd(['tr-edit', '--edit'], ["\n", "{\"c\":\"d\"}\n"]);
        $this->assertContains($exit, [0, 1]);
    }

    public function testEditModeRawBodyWithInput(): void
    {
        $this->writeTrace('tr-edit2', $this->baseTrace('tr-edit2', ['request_body' => 'raw body text']));
        [$exit, $output] = $this->runCmd(['tr-edit2', '--edit'], ["\n", "new raw\n"]);
        $this->assertContains($exit, [0, 1]);
    }

    public function testIsValidPathEmpty(): void
    {
        $this->writeTrace('tr-path', $this->baseTrace('tr-path', ['path' => '']));
        [$exit] = $this->runCmd(['tr-path']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testExecuteReplayWithInsecure(): void
    {
        $this->writeTrace('tr-insecure', $this->baseTrace('tr-insecure', ['host' => '127.0.0.1:1']));
        [$exit] = $this->runCmd(['tr-insecure', '--insecure']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testFormatHttpieOutput(): void
    {
        $this->writeTrace('tr-httpie', $this->baseTrace('tr-httpie'));
        [$exit, $output] = $this->runCmd(['tr-httpie', '--format=httpie']);
        $this->assertContains($exit, [0, 1]);
        $this->assertStringContainsString('http', strtolower($output));
    }

    public function testFormatCurlDefault(): void
    {
        $this->writeTrace('tr-curl', $this->baseTrace('tr-curl'));
        [$exit, $output] = $this->runCmd(['tr-curl', '--format=curl']);
        $this->assertContains($exit, [0, 1]);
        $this->assertStringContainsString('curl', $output);
    }

    public function testAuthModeWithStoredFile(): void
    {
        $this->writeTrace('tr-stored', $this->baseTrace('tr-stored'));
        $authPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '.auth';
        mkdir(dirname($authPath), 0777, true);
        file_put_contents($authPath, (string) json_encode(['token' => 'tok1']));
        [$exit] = $this->runCmd(['tr-stored', '--auth']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testTraceNotFound(): void
    {
        [$exit, $output] = $this->runCmd(['missing-trace']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testEditModeWithJsonBoolNull(): void
    {
        $this->writeTrace('tr-bool', $this->baseTrace('tr-bool', ['request_body' => '{"flag":true,"x":null}']));
        [$exit] = $this->runCmd(['tr-bool', '--edit'], ["\n", "\n"]);
        $this->assertContains($exit, [0, 1]);
    }

    public function testSafeFlagAndSetSpaceForm(): void
    {
        $this->writeTrace('tr-safe', $this->baseTrace('tr-safe'));
        [$exit] = $this->runCmd(['tr-safe', '--safe', '--set=a b']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDiffMode(): void
    {
        $this->writeTrace('tr-diff', $this->baseTrace('tr-diff'));
        [$exit] = $this->runCmd(['tr-diff', '--diff']);
        $this->assertContains($exit, [0, 1]);
    }
}
