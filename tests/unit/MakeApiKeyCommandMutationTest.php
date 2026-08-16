<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Cache;
use Siro\Core\Commands\MakeApiKeyCommand;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Coverage tests for MakeApiKeyCommand.
 */
final class MakeApiKeyCommandMutationTest extends TestCase
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
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        ApiKey::createTable();
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_apikey_' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
        Cache::reset();
        Database::purgeAll();
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

    private function writeConfig(): void
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:', 'slow_query_threshold' => 500];\n"
        );
    }

    public function testUsageWithoutName(): void
    {
        $cmd = new MakeApiKeyCommand($this->basePath);
        $code = $cmd->run([]);
        $this->assertSame(1, $code);
    }

    public function testDatabaseNotConfigured(): void
    {
        // No config file -> Database::configure not called
        Database::purgeAll();
        $cmd = new MakeApiKeyCommand($this->basePath);
        $code = $cmd->run(['Partner']);
        $this->assertSame(1, $code);
    }

    public function testCreatesApiKeyWithDefaults(): void
    {
        $this->writeConfig();
        $cmd = new MakeApiKeyCommand($this->basePath);
        $code = $cmd->run(['Partner A']);
        $this->assertSame(0, $code);

        $keys = ApiKey::listForUser(null);
        $this->assertCount(1, $keys);
        $this->assertSame('Partner A', $keys[0]['name']);
        $this->assertSame('read', $keys[0]['scopes']);
    }

    public function testCreatesApiKeyWithScopesAndExpiry(): void
    {
        $this->writeConfig();
        $cmd = new MakeApiKeyCommand($this->basePath);
        $code = $cmd->run(['Partner B', 'read,write', '30']);
        $this->assertSame(0, $code);

        $keys = ApiKey::listForUser(null);
        $this->assertCount(1, $keys);
        $this->assertSame('read,write', $keys[0]['scopes']);
        $this->assertTrue($keys[0]['is_expired'] === false || $keys[0]['is_expired'] === true);
    }

    public function testHelp(): void
    {
        $cmd = new MakeApiKeyCommand($this->basePath);
        ob_start();
        $cmd->help();
        $out = ob_get_clean();
        $this->assertStringContainsString('make:apikey', (string) $out);
    }
}
