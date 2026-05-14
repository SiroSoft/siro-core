<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;

final class EnvTest extends TestCase
{
    private array $envBackup;

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $_ENV = [];
        Env::reset();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
    }

    public function testLoadParsesSimpleKeyValue(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, 'APP_NAME=Siro');
        Env::load($file);
        $this->assertEquals('Siro', Env::get('APP_NAME'));
        unlink($file);
    }

    public function testLoadSkipsComments(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# comment\nKEY=value");
        Env::load($file);
        $this->assertEquals('value', Env::get('KEY'));
        unlink($file);
    }

    public function testLoadHandlesQuotedValues(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, 'APP_NAME="Siro API"');
        Env::load($file);
        $this->assertEquals('Siro API', Env::get('APP_NAME'));
        unlink($file);
    }

    public function testLoadHandlesSingleQuotedValues(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "KEY='value 123'");
        Env::load($file);
        $this->assertEquals('value 123', Env::get('KEY'));
        unlink($file);
    }

    public function testLoadSkipsEmptyLines(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "\n\nKEY=val\n\n");
        Env::load($file);
        $this->assertEquals('val', Env::get('KEY'));
        unlink($file);
    }

    public function testLoadHandlesEqualsInValue(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, 'JWT_SECRET=abc=def');
        Env::load($file);
        $this->assertEquals('abc=def', Env::get('JWT_SECRET'));
        unlink($file);
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull(Env::get('NONEXISTENT'));
    }

    public function testGetReturnsDefaultForMissing(): void
    {
        $this->assertEquals('default', Env::get('NONEXISTENT', 'default'));
    }

    public function testBoolReturnsTrueForTruthyValues(): void
    {
        $_ENV['TEST_BOOL'] = 'true';
        $this->assertTrue(Env::bool('TEST_BOOL'));
    }

    public function testBoolReturnsFalseForFalsyValues(): void
    {
        $_ENV['TEST_BOOL'] = 'false';
        $this->assertFalse(Env::bool('TEST_BOOL'));
    }

    public function testBoolReturnsDefaultForMissing(): void
    {
        $this->assertTrue(Env::bool('NONEXISTENT', true));
        $this->assertFalse(Env::bool('NONEXISTENT', false));
    }

    public function testBoolHandlesOne(): void
    {
        $_ENV['TEST'] = '1';
        $this->assertTrue(Env::bool('TEST'));
    }

    public function testBoolHandlesZero(): void
    {
        $_ENV['TEST'] = '0';
        $this->assertFalse(Env::bool('TEST'));
    }

    public function testLoadReturnsEarlyIfAlreadyLoaded(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "KEY1=first\nKEY2=second");
        Env::load($file);
        $this->assertEquals('first', Env::get('KEY1'));
        unlink($file);
    }

    public function testLoadReturnsEarlyForMissingFile(): void
    {
        Env::load('/nonexistent/.env');
        $this->assertNull(Env::get('ANYTHING'));
    }

    public function testLoadWithEmptyFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, '');
        Env::load($file);
        $this->assertNull(Env::get('ANY'));
        unlink($file);
    }

    public function testLoadWithOnlyComments(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# just a comment\n# another");
        Env::load($file);
        $this->assertNull(Env::get('ANY'));
        unlink($file);
    }

    public function testBoolWithYesReturnsTrue(): void
    {
        $_ENV['FLAG'] = 'yes';
        $this->assertTrue(Env::bool('FLAG'));
    }

    public function testGetReturnsFromEnv(): void
    {
        $_ENV['MY_VAR'] = 'my_value';
        $this->assertEquals('my_value', Env::get('MY_VAR'));
    }

    public function testLoadMultipleVars(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "A=1\nB=2\nC=3");
        Env::load($file);
        $this->assertEquals('1', Env::get('A'));
        $this->assertEquals('2', Env::get('B'));
        $this->assertEquals('3', Env::get('C'));
        unlink($file);
    }

    public function testCacheGeneratesPhpFile(): void
    {
        $_ENV['CACHE_TEST'] = 'cached_value';
        $cacheDir = sys_get_temp_dir() . '/siro_env_cache_test';
        $envFile = $cacheDir . '/.env';
        @mkdir($cacheDir, 0777, true);
        file_put_contents($envFile, "CACHE_TEST=cached_value");

        Env::load($envFile);
        $result = Env::cache($envFile);

        $this->assertTrue($result);
        $cacheFile = $cacheDir . '/storage/framework/env.php';
        $this->assertFileExists($cacheFile);

        $loaded = json_decode(substr((string) file_get_contents($cacheFile), 14), true);
        $this->assertIsArray($loaded);

        array_map('unlink', glob($cacheDir . '/storage/framework/*.php'));
        @rmdir($cacheDir . '/storage/framework');
        @rmdir($cacheDir . '/storage');
        @rmdir($cacheDir);
    }

    public function testClearCacheRemovesCacheFile(): void
    {
        $cacheDir = sys_get_temp_dir() . '/siro_clear_test';
        @mkdir($cacheDir . '/storage/framework', 0777, true);
        file_put_contents($cacheDir . '/storage/framework/env.php', '<?php return [];');
        file_put_contents($cacheDir . '/.env', 'KEY=val');

        Env::clearCache($cacheDir);
        $this->assertFileDoesNotExist($cacheDir . '/storage/framework/env.php');

        @rmdir($cacheDir . '/storage/framework');
        @rmdir($cacheDir . '/storage');
        @rmdir($cacheDir);
    }

    public function testIsLoadedAfterLoad(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, 'K=v');
        Env::load($file);
        $this->assertTrue(Env::isLoaded());
        unlink($file);
    }

    public function testResetClearsLoadedFlag(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, 'K=v');
        Env::load($file);
        $this->assertTrue(Env::isLoaded());
        Env::reset();
        $this->assertFalse(Env::isLoaded());
        unlink($file);
    }
}