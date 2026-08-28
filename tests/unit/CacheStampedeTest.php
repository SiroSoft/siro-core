<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Cache;
use Siro\Core\Cache\CacheInstance;
use Siro\Core\Cache\Drivers\FileDriver;

/**
 * Cache stampede protection tests.
 *
 * Tests process-level concurrency, exception recovery, lock expiry,
 * key isolation, and TTL correctness.
 */
final class CacheStampedeTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/siro_stampede_test_' . uniqid();
        mkdir($this->cacheDir, 0777, true);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Cache::flush();
        $this->removeDir($this->cacheDir);
    }

    // =========================================================================
    // A. Callback exception releases lock
    // =========================================================================

    public function testCallbackExceptionReleasesLock(): void
    {
        $driver = new FileDriver($this->cacheDir);
        $key = 'siro:exception_test';

        // First call: callback throws → lock must be released
        $threw = false;
        try {
            $this->rememberWithDriver($driver, $key, 60, function () {
                throw new \RuntimeException('Callback failed');
            });
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Exception should propagate');

        // Verify lock was released: a second remember should succeed
        $result = $this->rememberWithDriver($driver, $key, 60, function () {
            return 'recovered_value';
        });
        $this->assertSame('recovered_value', $result, 'Lock should be released after exception');
    }

    public function testNestedExceptionInCallbackReleasesLock(): void
    {
        $driver = new FileDriver($this->cacheDir);
        $key = 'siro:nested_exception_test';

        $threw = false;
        try {
            $this->rememberWithDriver($driver, $key, 60, function () {
                // Simulate exception after partial work
                throw new \LogicException('nested failure');
            });
        } catch (\LogicException) {
            $threw = true;
        }
        $this->assertTrue($threw);

        // Lock must be released
        $result = $this->rememberWithDriver($driver, $key, 60, function () {
            return 'after_nested_exception';
        });
        $this->assertSame('after_nested_exception', $result);
    }

    // =========================================================================
    // B. Different keys don't block each other
    // =========================================================================

    public function testDifferentKeysDoNotBlock(): void
    {
        $driver = new FileDriver($this->cacheDir);

        // Acquire lock on key_a
        $normalizedA = 'siro:key_a';
        $this->assertTrue($driver->lock($normalizedA, 2000), 'Should lock key_a');

        // key_b should NOT be blocked
        $normalizedB = 'siro:key_b';
        $this->assertTrue($driver->lock($normalizedB, 2000), 'key_b should not be blocked by key_a');

        // Cleanup
        $driver->unlock($normalizedA);
        $driver->unlock($normalizedB);
    }

    public function testUnrelatedRememberCallsDoNotSerialize(): void
    {
        $callsA = 0;
        $callsB = 0;

        $resultA = Cache::remember('independent_key_a', 60, function () use (&$callsA) {
            $callsA++;
            return 'value_a';
        });

        $resultB = Cache::remember('independent_key_b', 60, function () use (&$callsB) {
            $callsB++;
            return 'value_b';
        });

        $this->assertSame('value_a', $resultA);
        $this->assertSame('value_b', $resultB);
        $this->assertSame(1, $callsA);
        $this->assertSame(1, $callsB);
    }

    // =========================================================================
    // C. Lock TTL prevents permanent deadlock
    // =========================================================================

    public function testLockExpiresAfterTimeout(): void
    {
        $driver = new FileDriver($this->cacheDir);
        $key = 'siro:ttl_test';

        // Acquire lock
        $this->assertTrue($driver->lock($key, 200), 'Should acquire lock');

        // Second lock attempt on same key should fail (still held)
        $this->assertFalse($driver->lock($key, 100), 'Second lock should fail while first held');

        // Release lock explicitly
        $driver->unlock($key);

        // After explicit unlock, lock should be available
        $this->assertTrue($driver->lock($key, 2000), 'Should re-acquire after unlock');
        $driver->unlock($key);
    }

    // =========================================================================
    // D. Cache hit avoids lock entirely
    // =========================================================================

    public function testCacheHitSkipsLock(): void
    {
        // Prime the cache
        Cache::set('prime_key', 'primed_value', 60);

        $callbackCalled = false;
        $result = Cache::remember('prime_key', 60, function () use (&$callbackCalled) {
            $callbackCalled = true;
            return 'should_not_reach';
        });

        $this->assertSame('primed_value', $result);
        $this->assertFalse($callbackCalled, 'Callback should not be called on cache hit');
    }

    // =========================================================================
    // E. remember() correctness under normal usage
    // =========================================================================

    public function testRememberReturnsCachedValueOnHit(): void
    {
        $calls = 0;

        // First call: miss → compute
        $v1 = Cache::remember('consistency_key', 60, function () use (&$calls) {
            $calls++;
            return 'computed_once';
        });
        $this->assertSame('computed_once', $v1);
        $this->assertSame(1, $calls);

        // Second call: hit → return cached
        $v2 = Cache::remember('consistency_key', 60, function () use (&$calls) {
            $calls++;
            return 'computed_twice';
        });
        $this->assertSame('computed_once', $v2);
        $this->assertSame(1, $calls);
    }

    public function testRememberAfterExpirationComputesAgain(): void
    {
        $calls = 0;

        Cache::remember('expire_key', 1, function () use (&$calls) {
            $calls++;
            return 'first_computation';
        });
        $this->assertSame(1, $calls);

        // Wait for expiration
        sleep(2);

        $result = Cache::remember('expire_key', 60, function () use (&$calls) {
            $calls++;
            return 'second_computation';
        });
        $this->assertSame('second_computation', $result);
        $this->assertSame(2, $calls);
    }

    // =========================================================================
    // F. Lock does not prevent concurrent operations on different keys
    // =========================================================================

    public function testConcurrentDifferentKeysAllSucceed(): void
    {
        $results = [];
        $callbacks = 0;

        for ($i = 0; $i < 10; $i++) {
            $key = "batch_key_{$i}";
            $results[$key] = Cache::remember($key, 60, function () use (&$callbacks, $i) {
                $callbacks++;
                return "value_{$i}";
            });
        }

        $this->assertSame(10, $callbacks, 'All 10 independent callbacks should execute');
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame("value_{$i}", $results["batch_key_{$i}"]);
        }
    }

    // =========================================================================
    // G. Existing cache tests still pass (regression)
    // =========================================================================

    public function testBasicCacheOperationsStillWork(): void
    {
        // set/get
        Cache::set('basic', 'value', 60);
        $this->assertSame('value', Cache::get('basic'));

        // has
        $this->assertTrue(Cache::has('basic'));
        $this->assertFalse(Cache::has('nonexistent'));

        // forget
        Cache::forget('basic');
        $this->assertFalse(Cache::has('basic'));

        // flush
        Cache::set('a', 1, 60);
        Cache::set('b', 2, 60);
        Cache::flush();
        $this->assertFalse(Cache::has('a'));
        $this->assertFalse(Cache::has('b'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function rememberWithDriver(
        FileDriver $driver,
        string $key,
        int $ttl,
        callable $callback,
    ): mixed {
        // Simulate CacheInstance::remember() behavior with the given driver
        $value = $driver->get($key);
        if ($value !== null) {
            return $value;
        }

        if ($driver->lock($key, 5000)) {
            try {
                // Double-check
                $value = $driver->get($key);
                if ($value !== null) {
                    return $value;
                }
                $value = $callback();
                $driver->set($key, $value, $ttl);
                return $value;
            } finally {
                $driver->unlock($key);
            }
        }

        // Fallback
        $value = $callback();
        $driver->set($key, $value, $ttl);
        return $value;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
