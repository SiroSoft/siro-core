<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Config;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Schema;

/**
 * Config cache/load/set + Schema sqlite branches.
 */
final class ConfigSchemaMutationTest extends TestCase
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
        putenv('APP_KEY=this_is_a_sufficiently_long_app_key_for_config_123456');
        $this->basePath = sys_get_temp_dir() . '/siro_cfg_' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/storage/framework', 0777, true);
        file_put_contents($this->basePath . '/config/database.php', "<?php\nreturn ['driver' => 'sqlite', 'database' => ':memory:'];\n");
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('APP_KEY');
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        if (is_dir($this->basePath)) {
            system('rm -rf ' . escapeshellarg($this->basePath));
        }
        parent::tearDown();
    }

    public function testLoadAndGet(): void
    {
        Config::load($this->basePath . '/config');
        $this->assertSame('sqlite', Config::get('database.driver'));
        $this->assertSame('default', Config::get('missing.key', 'default'));
    }

    public function testGetCacheHit(): void
    {
        Config::load($this->basePath . '/config');
        Config::get('database.driver');
        $this->assertSame('sqlite', Config::get('database.driver'));
    }

    public function testSetAndHas(): void
    {
        Config::load($this->basePath . '/config');
        Config::set('app.debug', true);
        $this->assertTrue(Config::has('app.debug'));
        $this->assertFalse(Config::has('missing.key'));
        $this->assertSame(['driver' => 'sqlite', 'database' => ':memory:'], Config::all()['database']);
    }

    public function testSetNested(): void
    {
        Config::load($this->basePath . '/config');
        Config::set('a.b.c.d', 'deep');
        $this->assertSame('deep', Config::get('a.b.c.d'));
    }

    public function testLoadMissingDir(): void
    {
        Config::load($this->basePath . '/nonexistent-config');
        $this->assertNull(Config::get('anything'));
    }

    public function testCacheLoadValidHmac(): void
    {
        $data = ['database' => ['driver' => 'sqlite', 'database' => ':memory:']];
        $payload = json_encode($data);
        $secret = (string) Env::get('APP_KEY', '');
        $hmac = hash_hmac('sha256', $payload, $secret);
        $content = "<?php exit; ?>" . $payload . '.hmac.' . $hmac;
        $cacheFile = $this->basePath . '/storage/framework/config.php';
        file_put_contents($cacheFile, $content);
        // make cache newer than config dir
        touch($cacheFile, time() + 3600);
        Config::load($this->basePath . '/config');
        $this->assertSame('sqlite', Config::get('database.driver'));
        @unlink($cacheFile);
    }

    public function testSchemaSqlite(): void
    {
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Schema::dropIfExists('cfg_users');
        Schema::create('cfg_users', function (\Siro\Core\DB\Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        $this->assertTrue(Schema::hasTable('cfg_users'));
        Schema::drop('cfg_users');
        $this->assertFalse(Schema::hasTable('cfg_users'));
    }
}
