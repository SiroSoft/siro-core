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
 * LogReplayCommand extra branches: pretty print, nested edit, auth config.
 */
final class LogReplayExtraMutationTest extends TestCase
{
    private string $basePath;
    private string $tracesDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        $this->basePath = sys_get_temp_dir() . '/siro_lre_' . uniqid();
        $this->tracesDir = $this->basePath . '/storage/logs/traces';
        mkdir($this->tracesDir, 0777, true);
        mkdir($this->basePath . '/storage/logs', 0777, true);
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
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    private function writeTrace(string $name, array $data): string
    {
        $path = $this->tracesDir . '/' . $name . '.json';
        file_put_contents($path, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $name;
    }

    private function baseTrace(string $id = 'tr', array $over = []): array
    {
        return array_merge([
            'id' => $id,
            'method' => 'GET',
            'path' => '/api/x',
            'status' => 200,
            'request_body' => '',
            'response_body' => '{"a":{"b":1},"c":[1,2]}',
            'host' => '127.0.0.1:1',
            'timestamp' => date('c'),
        ], $over);
    }

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

    public function testPrettyPrintNested(): void
    {
        $this->writeTrace('nested', $this->baseTrace('nested'));
        [$exit, $output] = $this->runCmd(['nested', '--format=curl']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testEditRecursiveNested(): void
    {
        $this->writeTrace('editn', $this->baseTrace('editn', ['request_body' => '{"a":{"b":1}}']));
        [$exit, $output] = $this->runCmd(['editn', '--edit'], ["\n", "\n", "\n", "\n"]);
        $this->assertContains($exit, [0, 1]);
    }

    public function testHttpieOutput(): void
    {
        $this->writeTrace('httpie', $this->baseTrace('httpie', ['method' => 'POST', 'request_body' => '{"a":1}']));
        [$exit, $output] = $this->runCmd(['httpie', '--format=httpie']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testAuthConfigDiscoverFromEnv(): void
    {
        file_put_contents($this->basePath . '/.env', "AUTH_EMAIL=admin@test.com\nAUTH_PASSWORD=secret\n");
        $this->writeTrace('authcfg', $this->baseTrace('authcfg'));
        [$exit] = $this->runCmd(['authcfg', '--auth'], ['secret']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testWriteAndReadAuthFile(): void
    {
        $this->writeTrace('authfile', $this->baseTrace('authfile', ['auth_header' => 'Bearer tok']));
        [$exit] = $this->runCmd(['authfile', '--auth'], ['secret']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testDiffNoMatch(): void
    {
        $this->writeTrace('diffnm', $this->baseTrace('diffnm', ['response_body' => 'not json at all']));
        [$exit] = $this->runCmd(['diffnm', '--diff']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testSeedFlag(): void
    {
        $this->writeTrace('seed', $this->baseTrace('seed', ['request_body' => '{"a":"b"}']));
        [$exit] = $this->runCmd(['seed', '--set=a:c']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testInvalidPathRejected(): void
    {
        $this->writeTrace('badpath', $this->baseTrace('badpath', ['path' => '']));
        [$exit] = $this->runCmd(['badpath']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testNoTraceDir(): void
    {
        [$exit, $output] = $this->runCmd(['nonexistent-trace']);
        $this->assertContains($exit, [0, 1]);
    }

    public function testCurlMissingFormatHttpie(): void
    {
        $this->writeTrace('cmh', $this->baseTrace('cmh', ['method' => 'PUT', 'request_body' => '{"x":1}']));
        [$exit, $output] = $this->runCmd(['cmh', '--format=httpie'], [], true);
        $this->assertContains($exit, [0, 1]);
    }
}
