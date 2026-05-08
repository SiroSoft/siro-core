<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Cache;

final class CacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/siro_cache_test_' . uniqid();
        mkdir($this->cacheDir, 0777, true);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->cacheDir);
    }

    public function testSetAndGet(): void
    {
        Cache::set('test_key', 'test_value', 60);
        $this->assertSame('test_value', Cache::get('test_key'));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull(Cache::get('nonexistent'));
    }

    public function testHas(): void
    {
        Cache::set('exists_key', 'value', 60);
        $this->assertTrue(Cache::has('exists_key'));
        $this->assertFalse(Cache::has('missing_key'));
    }

    public function testForget(): void
    {
        Cache::set('forget_me', 'value', 60);
        $this->assertTrue(Cache::has('forget_me'));
        Cache::forget('forget_me');
        $this->assertFalse(Cache::has('forget_me'));
    }

    public function testRemember(): void
    {
        $counter = 0;
        $result = Cache::remember('remember_key', 60, function () use (&$counter) {
            $counter++;
            return 'computed_' . $counter;
        });
        $this->assertSame('computed_1', $result);

        $result2 = Cache::remember('remember_key', 60, function () use (&$counter) {
            $counter++;
            return 'computed_' . $counter;
        });
        $this->assertSame('computed_1', $result2);
        $this->assertSame(1, $counter);
    }

    public function testFlush(): void
    {
        Cache::set('key1', 'val1', 60);
        Cache::set('key2', 'val2', 60);
        $this->assertTrue(Cache::has('key1'));
        $this->assertTrue(Cache::has('key2'));

        Cache::flush();
        $this->assertFalse(Cache::has('key1'));
        $this->assertFalse(Cache::has('key2'));
    }

    public function testRequestStatus(): void
    {
        Cache::resetRequestState();
        $this->assertSame('MISS', Cache::requestStatus());

        Cache::set('status_key', 'val', 60);
        Cache::get('status_key');
        $this->assertSame('HIT', Cache::requestStatus());
    }

    public function testSetWithZeroTtl(): void
    {
        Cache::set('no_ttl', 'value', 0);
        $this->assertSame('value', Cache::get('no_ttl'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
