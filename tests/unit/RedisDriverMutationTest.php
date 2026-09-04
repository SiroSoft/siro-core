<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache\Drivers\RedisDriver;
use Siro\Core\Queue\RedisQueueDriver;

/**
 * Coverage for Redis drivers (requires a local Redis at 127.0.0.1:6379).
 */
final class RedisDriverMutationTest extends TestCase
{
    private \Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not available');
        }
        $this->redis = new \Redis();
        $ok = @$this->redis->connect('127.0.0.1', 6379, 1);
        if (!$ok) {
            $this->markTestSkipped('No Redis server at 127.0.0.1:6379');
        }
        $this->redis->flushAll();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->flushAll();
        }
        parent::tearDown();
    }

    public function testCacheRedisGetSet(): void
    {
        $driver = new RedisDriver($this->redis);
        $this->assertNull($driver->get('missing'));
        $this->assertTrue($driver->set('key1', ['a' => 1], 60));
        $this->assertSame(['a' => 1], $driver->get('key1'));
    }

    public function testCacheRedisSetStringValue(): void
    {
        $driver = new RedisDriver($this->redis);
        $driver->set('str', 'hello', 60);
        $this->assertSame('hello', $driver->get('str'));
    }

    public function testCacheRedisSetNullValue(): void
    {
        $driver = new RedisDriver($this->redis);
        $driver->set('nullval', null, 60);
        $this->assertNull($driver->get('nullval'));
    }

    public function testCacheRedisCorruptValue(): void
    {
        $driver = new RedisDriver($this->redis);
        $this->redis->set('corrupt', 'not-json');
        $this->assertNull($driver->get('corrupt'));
    }

    public function testCacheRedisDelete(): void
    {
        $driver = new RedisDriver($this->redis);
        $driver->set('del', 'x', 60);
        $this->assertNotNull($driver->get('del'));
        $this->assertTrue($driver->forget('del'));
        $this->assertNull($driver->get('del'));
    }

    public function testCacheRedisClear(): void
    {
        $driver = new RedisDriver($this->redis);
        $driver->set('c1', 1, 60);
        $driver->set('c2', 2, 60);
        $this->assertTrue($driver->clear());
        $this->assertNull($driver->get('c1'));
    }

    public function testCacheRedisIncrement(): void
    {
        $driver = new RedisDriver($this->redis);
        $driver->set('inc', 5, 60);
        $this->assertIsInt($driver->increment('inc'));
        $this->assertSame(6, $driver->get('inc'));
    }

    public function testQueueRedisDriver(): void
    {
        $driver = new RedisQueueDriver();
        // isAvailable depends on CacheInstance redis connection
        $this->assertIsBool($driver->isAvailable());
    }
}
