<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Config;
use Siro\Core\Env;

final class ConfigTest extends TestCase
{
    private string $configDir;
    private string $savedAppKeyEnv = '';
    private string $savedAppKeyGetenv = '';

    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        $this->savedAppKeyEnv = (string) ($_ENV['APP_KEY'] ?? '');
        $this->savedAppKeyGetenv = (string) getenv('APP_KEY');
        $_ENV['APP_KEY'] = 'test_key_for_config_cache_32chars_long!!';
        putenv('APP_KEY=test_key_for_config_cache_32chars_long!!');
        $this->configDir = sys_get_temp_dir() . '/siro_config_test_' . uniqid();
        mkdir($this->configDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->configDir);
        Config::reset();
        if ($this->savedAppKeyEnv === '') {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $this->savedAppKeyEnv;
        }
        putenv('APP_KEY=' . $this->savedAppKeyGetenv);
    }

    public function testLoadFromDirectory(): void
    {
        file_put_contents($this->configDir . '/app.php', '<?php return ["name" => "Siro", "debug" => true];');
        file_put_contents($this->configDir . '/database.php', '<?php return ["host" => "localhost", "port" => 3306];');

        Config::load($this->configDir);
        $this->assertSame('Siro', Config::get('app.name'));
        $this->assertTrue(Config::get('app.debug'));
        $this->assertSame('localhost', Config::get('database.host'));
        $this->assertSame(3306, Config::get('database.port'));
    }

    public function testGetWithDefault(): void
    {
        Config::load($this->configDir);
        $this->assertSame('default', Config::get('nonexistent.key', 'default'));
        $this->assertNull(Config::get('nonexistent.key'));
    }

    public function testSet(): void
    {
        Config::load($this->configDir);
        Config::set('app.name', 'SiroPHP');
        $this->assertSame('SiroPHP', Config::get('app.name'));
    }

    public function testSetNested(): void
    {
        Config::load($this->configDir);
        Config::set('services.mail.driver', 'smtp');
        $this->assertSame('smtp', Config::get('services.mail.driver'));
    }

    public function testHas(): void
    {
        file_put_contents($this->configDir . '/app.php', '<?php return ["name" => "Siro"];');
        Config::load($this->configDir);
        $this->assertTrue(Config::has('app.name'));
        $this->assertFalse(Config::has('app.nonexistent'));
    }

    public function testAll(): void
    {
        file_put_contents($this->configDir . '/app.php', '<?php return ["key" => "value"];');
        Config::load($this->configDir);
        $all = Config::all();
        $this->assertArrayHasKey('app', $all);
        $this->assertSame('value', $all['app']['key']);
    }

    public function testIsLoaded(): void
    {
        $this->assertFalse(Config::isLoaded());
        Config::load($this->configDir);
        $this->assertTrue(Config::isLoaded());
    }

    public function testCacheAndClearCache(): void
    {
        file_put_contents($this->configDir . '/app.php', '<?php return ["name" => "Siro"];');
        Config::load($this->configDir);
        $cacheFile = Config::cache();
        $this->assertNotNull($cacheFile);
        $this->assertFileExists($cacheFile);

        Config::clearCache();
        $this->assertFileDoesNotExist($cacheFile);
    }

    public function testLoadNonExistentDirectory(): void
    {
        Config::load('/nonexistent/config/path');
        $this->assertTrue(Config::isLoaded());
        $this->assertSame([], Config::all());
    }

    public function testSetOverwritesCache(): void
    {
        file_put_contents($this->configDir . '/app.php', '<?php return ["name" => "Old"];');
        Config::load($this->configDir);
        $this->assertSame('Old', Config::get('app.name'));

        Config::set('app.name', 'New');
        $this->assertSame('New', Config::get('app.name'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
