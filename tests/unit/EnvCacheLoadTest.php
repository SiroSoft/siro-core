<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;

/**
 * Env cache load-path coverage: encrypted cache round-trip, cache reuse on load,
 * APP_KEY requirements.
 */
final class EnvCacheLoadTest extends TestCase
{
    private string $dir;
    private string $envFile;

    protected function setUp(): void
    {
        Env::reset();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_envcache_' . uniqid('', true);
        $this->envFile = $this->dir . DIRECTORY_SEPARATOR . '.env';
        mkdir($this->dir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework', 0777, true);
    }

    protected function tearDown(): void
    {
        Env::reset();
        $bootstrapKey = 'test_app_key_for_encryption_tests_32chars!!';
        putenv('APP_KEY=' . $bootstrapKey);
        $_ENV['APP_KEY'] = $bootstrapKey;
        if (is_dir($this->dir)) {
            $this->rmDir($this->dir);
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

    public function testCachedLoadWithEncryptedPayload(): void
    {
        $appKey = 'this_is_a_test_app_key_of_32_chars_!!';
        putenv('APP_KEY=' . $appKey);
        $_ENV['APP_KEY'] = $appKey;

        file_put_contents($this->envFile, "CACHE_KEY=cached_value\nAPP_KEY={$appKey}\n");
        Env::load($this->envFile);
        $this->assertTrue(Env::cache($this->envFile));

        // Reset so load() re-reads and hits the cache branch
        Env::reset();
        Env::load($this->envFile);
        $this->assertSame('cached_value', Env::get('CACHE_KEY', ''));
    }

    public function testCacheRequiresAppKeyForIntegrity(): void
    {
        putenv('APP_KEY=');
        unset($_ENV['APP_KEY']);
        file_put_contents($this->envFile, 'FOO=bar');

        Env::load($this->envFile);
        Env::cache($this->envFile);

        // Reading an unencrypted cache without a strong APP_KEY throws.
        Env::reset();
        $this->expectException(\RuntimeException::class);
        Env::load($this->envFile);
    }
}
