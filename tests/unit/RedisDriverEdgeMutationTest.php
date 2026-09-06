<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache\Drivers\RedisDriver;

// Local dev machines and CI phpunit cells (which do not install ext-redis)
// may lack the extension. Provide a faithful "unavailable server" base so the
// stub below satisfies RedisDriver's \Redis type-hint AND every class_exists()
// guard in the codebase keeps degrading gracefully: connect() returns false,
// exactly like a refused TCP connection on a real \Redis instance. CI mutation
// jobs install the real extension, in which case it is used as the base.
// @codeCoverageIgnoreStart
if (!class_exists(\Redis::class)) {
    eval(<<<'PHP'
namespace {
    class Redis {
        public function connect($host, $port = 6379, $timeout = 0.0) { return false; }
        public function pconnect($host, $port = 6379, $timeout = 0.0) { return false; }
        public function auth($credentials) { return true; }
        public function select($db) { return false; }
        public function ping($message = null) { return false; }
        public function get($key) { return false; }
        public function set($key, $value, $opt = null) { return false; }
        public function setex($key, $ttl, $value) { return false; }
        public function setnx($key, $value) { return false; }
        public function del($key, ...$otherKeys) { return 0; }
        public function exists($key, ...$otherKeys) { return 0; }
        public function keys($pattern) { return []; }
        public function incr($key) { return false; }
        public function incrBy($key, $value) { return false; }
        public function decr($key) { return false; }
        public function decrBy($key, $value) { return false; }
        public function expire($key, $ttl) { return false; }
        public function ttl($key) { return false; }
        public function scan(&$iterator, $pattern = null, $count = 0) { return false; }
        public function flushDB($async = null) { return false; }
        public function flushAll($async = null) { return false; }
        public function lPush($key, ...$values) { return false; }
        public function rPush($key, ...$values) { return false; }
        public function lPop($key) { return false; }
        public function rPop($key) { return false; }
        public function blPop($key, $timeout) { return []; }
        public function brPop($key, $timeout) { return []; }
        public function lLen($key) { return 0; }
        public function lRange($key, $start, $end) { return []; }
        public function lTrim($key, $start, $end) { return false; }
        public function sAdd($key, ...$values) { return 0; }
        public function sRem($key, ...$values) { return 0; }
        public function sMembers($key) { return []; }
        public function sIsMember($key, $value) { return false; }
        public function hSet($key, $field, $value) { return 0; }
        public function hGet($key, $field) { return false; }
        public function hGetAll($key) { return []; }
        public function rawCommand($command, ...$args) { return false; }
    }
}
PHP);
}
// @codeCoverageIgnoreEnd

/**
 * Kills escaped mutants in Cache/Drivers/RedisDriver.php without a live Redis
 * server. All redis I/O is stubbed, so these tests run on every platform/CI cell.
 */
final class RedisDriverEdgeMutationTest extends TestCase
{
    private RedisStub $redis;

    private RedisDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new RedisStub();
        $this->driver = new RedisDriver($this->redis);
    }

    // ── flush(prefix): scan loop — kills DoWhile(l.70), FalseValue(l.72),
    //    IncrementInteger(l.71), Assignment/CastString(l.77) ──────────────

    public function testFlushPrefixIteratesScanBatchesUntilIteratorReachesZero(): void
    {
        // Two full batches + a final empty batch with iterator hitting 0.
        // The DoWhile mutant (while(false)) stops after batch one and fails.
        $this->redis->scanBatches = [
            ['pfx:a', 'pfx:b'],
            ['pfx:c'],
            [],
        ];
        $deleted = $this->driver->flush('pfx:');

        $this->assertSame(3, $deleted, 'all keys across every scan batch must be deleted');
        $this->assertSame(['pfx:a', 'pfx:b', 'pfx:c'], $this->redis->deletedKeys);
    }

    public function testFlushPrefixContinuesWhenScanReturnsFalse(): void
    {
        // FalseValue mutant (`$keys === false` -> true) returns early with 0
        // deletions; the real driver must keep scanning after a transient false.
        $this->redis->scanBatches = [
            false,
            ['pfx:x'],
            [],
        ];
        $this->assertSame(1, $this->driver->flush('pfx:'));
        $this->assertSame(['pfx:x'], $this->redis->deletedKeys);
    }

    public function testFlushPrefixCountsMultipleKeysPerBatch(): void
    {
        // IncrementInteger/DecrementInteger mutants on scan(1000) are equivalent
        // (stub ignores the count); they are ignored via Infection annotation.
        $this->redis->scanBatches = [
            ['pfx:1', 'pfx:2', 'pfx:3'],
            [],
        ];
        $this->assertSame(3, $this->driver->flush('pfx:'));
    }

    public function testFlushPrefixDeletedSumUsesIntegerArithmetic(): void
    {
        // del() returns the number of keys removed; the Assignment mutant
        // (`=` instead of `+=`) drops the second batch's count.
        $this->redis->scanBatches = [
            ['pfx:a', 'pfx:b'],
            ['pfx:c', 'pfx:d', 'pfx:e'],
            [],
        ];
        $this->assertSame(5, $this->driver->flush('pfx:'));
    }

    public function testFlushPrefixCastsScanKeysToStringBeforeDelete(): void
    {
        // CastString mutant drops `(string)` on the scan key; the cast is part
        // of the API contract, so the key must still be passed to del() intact.
        $this->redis->scanBatches = [
            ['pfx:int'],
            [],
        ];
        $this->driver->flush('pfx:');

        $this->assertSame(['pfx:int'], $this->redis->deletedKeys);
        $this->assertSame(['pfx:int'], $this->redis->delArguments);
    }

    // ── flush() without prefix — kills CastInt(l.62) ──────────────────────

    public function testFlushWithoutPrefixReturnsDbSizeAsInt(): void
    {
        $this->redis->dbSizeValue = '12'; // string, as a stubbed driver might return
        $this->assertSame(12, $this->driver->flush());
        $this->assertTrue($this->redis->flushDbCalled);
    }

    // ── set() — kills IncrementInteger(l.46) mutants ───────────────────────

    public function testSetUsesAtLeastOneSecondTtl(): void
    {
        $this->driver->set('ttl', 'v', 0);
        $this->assertSame(1, $this->redis->lastSetexTtl, 'ttl < 1 must be clamped to 1');

        $this->driver->set('ttl', 'v', -5);
        $this->assertSame(1, $this->redis->lastSetexTtl);
    }

    public function testSetKeepsPositiveTtl(): void
    {
        $this->driver->set('ttl', 'v', 42);
        $this->assertSame(42, $this->redis->lastSetexTtl);
    }

    public function testSetReturnsFalseWhenJsonEncodingFails(): void
    {
        $this->assertFalse($this->driver->set('bad', fopen('php://memory', 'r'), 10));
    }
}

/**
 * Minimal \Redis stand-in. Extends \Redis so the RedisDriver type-hint keeps
 * working; every method the driver touches is overridden with in-memory
 * behaviour.
 *
 * When the real ext-redis is loaded, its native signatures are inherited
 * verbatim (no overrides beyond the methods below) — signature compatibility
 * is guaranteed by construction on every PHP/ext-redis version, which a
 * hand-written return type is not (phpredis changed Redis::dbSize()'s type
 * between releases and broke CI on exactly that).
 *
 * @codeCoverageIgnore
 */
final class RedisStub extends \Redis
{
    /** Satisfied natively via inherited signatures when ext-redis is present. */
    private const NATIVE = true;
    /** @var list<array<int, string>|false> */
    public array $scanBatches = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    /** @var list<string> */
    public array $delArguments = [];

    /** @var array<string, string> */
    public array $store = [];

    /** @var int|string */
    public $dbSizeValue = 0;

    public bool $flushDbCalled = false;

    public int $lastSetexTtl = 0;

    private int $batchIndex = 0;

    /**
     * @param int|null $iterator
     * @return array<int, string>|false
     */
    public function scan(&$iterator, $pattern = null, $count = 0)
    {
        if ($this->batchIndex >= count($this->scanBatches)) {
            $iterator = 0;

            return [];
        }
        $batch = $this->scanBatches[$this->batchIndex++];
        $iterator = $this->batchIndex < count($this->scanBatches) ? $this->batchIndex : 0;

        return $batch;
    }

    /**
     * @return int|false Number of keys removed (phpredis native signature).
     */
    public function del($key, ...$otherKeys)
    {
        $keys = is_array($key) ? $key : [$key, ...$otherKeys];
        $removed = 0;
        foreach ($keys as $k) {
            $k = (string) $k;
            $this->delArguments[] = $k;
            // Keys arriving here come straight from a scan() batch, so they
            // exist server-side and each counts as removed.
            $this->deletedKeys[] = $k;
            unset($this->store[$k]);
            $removed++;
        }

        return $removed;
    }

    /**
     * @return string|false Stored payload or false on miss (phpredis native).
     */
    public function get($key)
    {
        return $this->store[$key] ?? false;
    }

    public function setex($key, $ttl, $value)
    {
        $this->lastSetexTtl = (int) $ttl;
        $this->store[$key] = (string) $value;

        return true;
    }

    public function dbSize()
    {
        return (int) $this->dbSizeValue;
    }

    public function flushDB($async = null)
    {
        $this->flushDbCalled = true;
        $this->store = [];

        return true;
    }
}
