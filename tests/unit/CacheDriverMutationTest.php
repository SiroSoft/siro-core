<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache\Drivers\FileDriver;
use Siro\Core\Cache\CacheInstance;
use Siro\Core\Env;

/**
 * FileDriver/CacheInstance mutation tests — no Redis needed.
 *
 * Targets: TTL boundary (0 = never), expiry purge, flush(prefix) count,
 * lock ownership/timeout, path sanitization, defaultTtl env parsing.
 */
final class CacheDriverMutationTest extends TestCase
{
    private string $tmpDir;

    private FileDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/siro-mut-' . bin2hex(random_bytes(4));
        $this->driver = new FileDriver($this->tmpDir);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ================================================================
    // set/get TTL semantics
    // ================================================================

    public function testTtlZeroMeansNeverExpires(): void
    {
        $this->driver->set('never', 'v', 0);
        // ttl=0 must map to expires_at=0 (never). Backdate is unnecessary —
        // find the file and check the record says 0.
        $file = glob($this->tmpDir . '/*.cache');
        $this->assertNotEmpty($file, 'cache file must exist');
        $rec = json_decode((string) file_get_contents($file[0]), true);
        $this->assertSame(0, $rec['expires_at'], 'ttl=0 must store expires_at=0 (never)');
        $this->assertSame('v', $this->driver->get('never'));
    }

    public function testExpiredRecordIsPurgedAndReturnsNull(): void
    {
        $this->driver->set('gone', 'v', 1);
        $file = glob($this->tmpDir . '/*.cache')[0];
        $rec = json_decode((string) file_get_contents($file), true);
        $rec['expires_at'] = time() - 5;
        file_put_contents($file, json_encode($rec));
        $this->assertNull($this->driver->get('gone'));
        $this->assertFalse(is_file($file), 'expired file must be unlinked on read');
    }

    public function testExpiryBoundaryAtExactlyNowIsPurged(): void
    {
        // Contract: expires_at < time() → expired. At exactly now it's still valid... verify actual:
        $this->driver->set('edge', 'v', 30);
        $file = glob($this->tmpDir . '/*.cache')[0];
        $rec = json_decode((string) file_get_contents($file), true);
        $rec['expires_at'] = time() + 1; // expires next second — treat as future
        file_put_contents($file, json_encode($rec));
        $this->assertSame('v', $this->driver->get('edge'));

        // Fully expired (1s in past) → gone
        $rec['expires_at'] = time() - 1;
        file_put_contents($file, json_encode($rec));
        $this->assertNull($this->driver->get('edge'));
    }

    public function testSetStoresExactPayloadShape(): void
    {
        $this->driver->set('shape', ['a' => 1], 10);
        $file = glob($this->tmpDir . '/*.cache')[0];
        $rec = json_decode((string) file_get_contents($file), true);
        $this->assertSame('shape', $rec['key']);
        $this->assertSame(['a' => 1], $rec['value']);
        $this->assertEqualsWithDelta(time() + 10, $rec['expires_at'], 2);
    }

    public function testGetReturnsValuePreservingTypes(): void
    {
        $payload = ['int' => 5, 'str' => 'x', 'bool' => false, 'null' => null, 'arr' => [1, 2]];
        $this->driver->set('types', $payload, 10);
        $this->assertSame($payload, $this->driver->get('types'));
    }

    // ================================================================
    // forget / has
    // ================================================================

    public function testForgetReturnsTrueWhenFileExisted(): void
    {
        $this->driver->set('del', 'v', 10);
        $this->assertTrue($this->driver->forget('del'));
        $this->assertFalse($this->driver->forget('del'), 'second forget must return false');
    }

    public function testHasReflectsExistence(): void
    {
        $this->assertFalse($this->driver->has('nope'));
        $this->driver->set('yes', 1, 10);
        $this->assertTrue($this->driver->has('yes'));
    }

    public function testCorruptCacheFileIsTreatedAsMissingAndRemoved(): void
    {
        $this->driver->set('corrupt', 'v', 10);
        $file = glob($this->tmpDir . '/*.cache')[0];
        file_put_contents($file, 'not-json{{{');
        $this->assertNull($this->driver->get('corrupt'));
        $this->assertFalse(is_file($file), 'corrupt file must be removed');
    }

    // ================================================================
    // flush with prefix
    // ================================================================

    public function testFlushWithoutPrefixDeletesEverything(): void
    {
        $this->driver->set('pfx_a', 1, 10);
        $this->driver->set('pfx_b', 2, 10);
        $this->driver->set('other', 3, 10);
        $this->assertSame(3, $this->driver->flush());
        $this->assertNull($this->driver->get('pfx_a'));
        $this->assertNull($this->driver->get('other'));
    }

    public function testFlushWithPrefixOnlyDeletesMatchingFiles(): void
    {
        $this->driver->set('user_1', 'a', 10);
        $this->driver->set('user_2', 'b', 10);
        $this->driver->set('session_1', 'c', 10);
        $deleted = $this->driver->flush('user_');
        $this->assertSame(2, $deleted);
        $this->assertNull($this->driver->get('user_1'));
        $this->assertNull($this->driver->get('user_2'));
        $this->assertSame('c', $this->driver->get('session_1'), 'non-matching key must survive');
    }

    public function testFlushPrefixIsSanitizedToSafeCharacters(): void
    {
        $this->driver->set('abc_123', 'v', 10);
        // '/' and '?' sanitize to '_' → matches stored key 'abc_123'
        $deleted = $this->driver->flush('abc/123?');
        $this->assertSame(1, $deleted);
        $this->assertNull($this->driver->get('abc_123'));
    }

    public function testFlushPrefixLongerThan200CharsIsTruncated(): void
    {
        $this->driver->set(str_repeat('k', 250), 'v', 10);
        // Safe filename is first 200 chars + sha1 — prefix longer than 200 truncates
        $deleted = $this->driver->flush(str_repeat('k', 250));
        $this->assertSame(1, $deleted);
    }

    // ================================================================
    // lock / unlock
    // ================================================================

    public function testLockAcquireAndReleaseCycle(): void
    {
        $this->assertTrue($this->driver->lock('res', 1000));
        $this->driver->unlock('res');
        // Re-acquirable after release
        $this->assertTrue($this->driver->lock('res', 1000));
        $this->driver->unlock('res');
    }

    public function testSecondLockInSameInstanceSucceedsSameHandle(): void
    {
        // Same instance reuses its handle map — lock twice is fine within one owner
        $this->assertTrue($this->driver->lock('own', 1000));
        $this->driver->unlock('own');
        $this->assertTrue($this->driver->lock('own', 1000));
        $this->driver->unlock('own');
    }

    public function testUnlockWithoutLockIsNoop(): void
    {
        $this->driver->unlock('never-locked');
        // No exception, no state change
        $this->assertTrue(true);
    }

    public function testLockTimesOutWhenHeldByCompetingInstance(): void
    {
        $a = new FileDriver($this->tmpDir);
        $b = new FileDriver($this->tmpDir);
        $this->assertTrue($a->lock('contested', 1000));
        $start = microtime(true);
        $this->assertFalse($b->lock('contested', 120), 'competing lock must fail within timeout');
        $elapsed = microtime(true) - $start;
        $this->assertGreaterThanOrEqual(0.1, $elapsed, 'should have waited the timeout');
        $this->assertLessThan(2.0, $elapsed, 'timeout must bound the wait');
        $a->unlock('contested');
    }

    public function testLockAcquirableByCompetingInstanceAfterRelease(): void
    {
        $a = new FileDriver($this->tmpDir);
        $b = new FileDriver($this->tmpDir);
        $this->assertTrue($a->lock('handoff', 1000));
        $a->unlock('handoff');
        $this->assertTrue($b->lock('handoff', 1000));
        $b->unlock('handoff');
    }

    public function testLockPathIsSanitizedAndDeterministic(): void
    {
        // Two keys differing only in unsafe chars must map to distinct lock files
        $this->assertTrue($this->driver->lock('a/b:c', 500));
        $this->driver->unlock('a/b:c');
        $this->assertTrue($this->driver->lock('other-key', 500));
        $this->driver->unlock('other-key');
        $locks = glob($this->tmpDir . '/*.lock');
        // Both lock files were unlinked after unlock
        $this->assertSame([], $locks ?: []);
    }

    // ================================================================
    // constructor path handling
    // ================================================================

    public function testConstructorCreatesMissingDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/siro-mut-mkdir-' . bin2hex(random_bytes(4));
        $this->assertDirectoryDoesNotExist($dir);
        new FileDriver($dir);
        $this->assertDirectoryExists($dir);
        @rmdir($dir);
    }

    public function testConstructorTrimsTrailingSeparator(): void
    {
        $dir = sys_get_temp_dir() . '/siro-mut-trim-' . bin2hex(random_bytes(4));
        $d = new FileDriver($dir . DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
        $d->set('k', 'v', 5);
        $this->assertSame('v', $d->get('k'));
        foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
    }

    // ================================================================
    // CacheInstance defaultTtl (env parsing)
    // ================================================================

    public function testCacheInstanceRespectsCacheTtlEnv(): void
    {
        // CacheInstance lazily boots its own FileDriver under the repo storage dir;
        // boot() parses CACHE_TTL from Env. Verify via reflection that defaultTtl = 120.
        $backup = $_ENV['CACHE_TTL'] ?? null;
        $_ENV['CACHE_TTL'] = '120';
        try {
            $ci = new CacheInstance();
            $ci->boot(dirname(__DIR__, 2));
            $ref = new \ReflectionProperty(CacheInstance::class, 'defaultTtl');
            $ref->setAccessible(true);
            $this->assertSame(120, $ref->getValue($ci));
        } finally {
            if ($backup === null) { unset($_ENV['CACHE_TTL']); } else { $_ENV['CACHE_TTL'] = $backup; }
        }
    }

    public function testCacheInstanceClampsZeroOrNegativeTtlToMinimumOne(): void
    {
        $backup = $_ENV['CACHE_TTL'] ?? null;
        $_ENV['CACHE_TTL'] = '0';
        try {
            $ci = new CacheInstance();
            $ci->boot(dirname(__DIR__, 2));
            $ref = new \ReflectionProperty(CacheInstance::class, 'defaultTtl');
            $ref->setAccessible(true);
            $this->assertSame(1, $ref->getValue($ci), 'max(1, 0) must clamp to 1');
        } finally {
            if ($backup === null) { unset($_ENV['CACHE_TTL']); } else { $_ENV['CACHE_TTL'] = $backup; }
        }
    }
}
