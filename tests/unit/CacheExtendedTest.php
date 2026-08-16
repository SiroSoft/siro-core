<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Cache;

/**
 * Extended Cache tests — remember callback, request status, flush prefix,
 * query-builder flush, TTL fallback, forget/has edge cases.
 */
final class CacheExtendedTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/siro_cache_ext_' . uniqid();
        mkdir($this->cacheDir, 0777, true);
        Cache::flush();
        Cache::resetRequestState();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Cache::flush();
        if (is_dir($this->cacheDir)) {
            $this->removeDir($this->cacheDir);
        }
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

    public function testRememberComputesOnceOnMiss(): void
    {
        $calls = 0;
        $value = Cache::remember('compute_key', 60, function () use (&$calls) {
            $calls++;
            return 'computed-' . $calls;
        });
        $this->assertSame('computed-1', $value);
        $this->assertSame(1, $calls);

        // Second call hits cache, callback not invoked
        $again = Cache::remember('compute_key', 60, function () use (&$calls) {
            $calls++;
            return 'computed-' . $calls;
        });
        $this->assertSame('computed-1', $again);
        $this->assertSame(1, $calls, 'callback should not re-run on cache hit');
    }

    public function testRequestStatusMissThenHit(): void
    {
        $this->assertSame('MISS', Cache::requestStatus()['status']);
        Cache::get('any_missing');
        $this->assertSame('MISS', Cache::requestStatus()['status']);

        Cache::set('hit_key', 'x', 60);
        Cache::get('hit_key');
        $this->assertSame('HIT', Cache::requestStatus()['status']);

        Cache::resetRequestState();
        $this->assertSame('MISS', Cache::requestStatus()['status']);
    }

    public function testFlushWithPrefix(): void
    {
        Cache::set('users:1', 'a', 60);
        Cache::set('users:2', 'b', 60);
        Cache::set('orders:1', 'c', 60);
        $cleared = Cache::flush('users:');
        $this->assertGreaterThanOrEqual(2, $cleared);
        $this->assertFalse(Cache::has('users:1'));
        $this->assertFalse(Cache::has('users:2'));
        $this->assertTrue(Cache::has('orders:1'), 'orders should survive users flush');
    }

    public function testFlushQueryBuilderTable(): void
    {
        Cache::set('qb:products:list', 'x', 60);
        Cache::set('qb:products:detail:5', 'y', 60);
        Cache::set('qb:orders:list', 'z', 60);
        $cleared = Cache::flushQueryBuilderTable('products');
        $this->assertGreaterThanOrEqual(2, $cleared);
        $this->assertFalse(Cache::has('qb:products:list'));
        $this->assertFalse(Cache::has('qb:products:detail:5'));
        $this->assertTrue(Cache::has('qb:orders:list'));
    }

    public function testFlushQueryBuilderEmptyTableReturnsZero(): void
    {
        $this->assertSame(0, Cache::flushQueryBuilderTable('   '));
    }

    public function testForgetReturnsTrueForExisting(): void
    {
        Cache::set('del_key', 'v', 60);
        $this->assertTrue(Cache::forget('del_key'));
        $this->assertFalse(Cache::has('del_key'));
    }

    public function testStoredNullValueIsCached(): void
    {
        Cache::set('null_key', null, 60);
        $this->assertTrue(Cache::has('null_key'));
        // get returns null for both missing and stored-null, but has() distinguishes
        $this->assertNull(Cache::get('null_key'));
    }

    public function testSetWithNegativeTtlUsesDefault(): void
    {
        Cache::set('neg_ttl', 'kept', -5);
        // Should not throw; value stored (default ttl fallback)
        $this->assertSame('kept', Cache::get('neg_ttl'));
    }
}
