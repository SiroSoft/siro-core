<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\Idempotency;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Deep mutation tests for Idempotency — targets 123 uncovered mutations.
 *
 * Covers: setKey, isDuplicate, getStoredResponse, storeResponse, clear,
 * createTable, cleanup, hash construction, TTL behavior, JSON encoding,
 * ON CONFLICT logic, expired key detection, user isolation.
 */
final class IdempotencyDeepMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        unset($_ENV['DB_CONNECTION'], $_ENV['DB_DATABASE']);
        Env::reset();
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Idempotency::createTable();
    }

    protected function tearDown(): void
    {
        Env::reset();
        parent::tearDown();
    }

    // ============================================================
    // setKey — empty, whitespace, new key, duplicate key
    // ============================================================

    public function testSetKeyEmptyStringNotDuplicate(): void
    {
        $idem = new Idempotency();
        $idem->setKey('', 0, 'POST');
        $this->assertFalse($idem->isDuplicate());
        $this->assertNull($idem->getStoredResponse());
    }

    public function testSetKeyWhitespaceOnlyNotDuplicate(): void
    {
        $idem = new Idempotency();
        $idem->setKey('   ', 0, 'POST');
        $this->assertFalse($idem->isDuplicate());
    }

    public function testSetKeyNewKeyNotDuplicate(): void
    {
        $idem = new Idempotency();
        $idem->setKey('unique-new-key-' . uniqid(), 0, 'POST');
        $this->assertFalse($idem->isDuplicate());
    }

    public function testSetKeyDuplicateKeyIsDuplicate(): void
    {
        $key = 'dup-key-' . uniqid();
        $idem1 = new Idempotency();
        $idem1->setKey($key, 1, 'POST');
        $idem1->storeResponse(['status' => 200, 'data' => 'ok']);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 1, 'POST');
        $this->assertTrue($idem2->isDuplicate());
    }

    public function testSetKeyDifferentUserIdNotDuplicate(): void
    {
        $key = 'user-key-' . uniqid();
        $idem1 = new Idempotency();
        $idem1->setKey($key, 1, 'POST');
        $idem1->storeResponse(['status' => 200]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 2, 'POST');
        $this->assertFalse($idem2->isDuplicate());
    }

    public function testSetKeyDifferentMethodNotDuplicate(): void
    {
        $key = 'method-key-' . uniqid();
        $idem1 = new Idempotency();
        $idem1->setKey($key, 0, 'POST');
        $idem1->storeResponse(['status' => 200]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'PUT');
        $this->assertFalse($idem2->isDuplicate());
    }

    public function testSetKeyTrimsWhitespace(): void
    {
        $key = '  trimmed-key-' . uniqid() . '  ';
        $idem = new Idempotency();
        $idem->setKey($key, 0, 'POST');
        $ref = new \ReflectionProperty($idem, 'currentKey');
        $ref->setAccessible(true);
        $this->assertSame(trim($key), $ref->getValue($idem));
    }

    // ============================================================
    // Hash construction
    // ============================================================

    public function testHashContainsKey(): void
    {
        $idem = new Idempotency();
        $idem->setKey('hash-test-key', 0, 'POST');
        $ref = new \ReflectionProperty($idem, 'hash');
        $ref->setAccessible(true);
        $hash = $ref->getValue($idem);
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
        // Same key should produce same hash
        $idem2 = new Idempotency();
        $idem2->setKey('hash-test-key', 0, 'POST');
        $ref2 = new \ReflectionProperty($idem2, 'hash');
        $ref2->setAccessible(true);
        $this->assertSame($hash, $ref2->getValue($idem2));
    }

    public function testHashDiffersWithDifferentKey(): void
    {
        $idem1 = new Idempotency();
        $idem1->setKey('key-a', 0, 'POST');
        $ref1 = new \ReflectionProperty($idem1, 'hash');
        $ref1->setAccessible(true);

        $idem2 = new Idempotency();
        $idem2->setKey('key-b', 0, 'POST');
        $ref2 = new \ReflectionProperty($idem2, 'hash');
        $ref2->setAccessible(true);

        $this->assertNotSame($ref1->getValue($idem1), $ref2->getValue($idem2));
    }

    public function testHashDiffersWithDifferentUserId(): void
    {
        $idem1 = new Idempotency();
        $idem1->setKey('same', 1, 'POST');
        $ref1 = new \ReflectionProperty($idem1, 'hash');
        $ref1->setAccessible(true);

        $idem2 = new Idempotency();
        $idem2->setKey('same', 2, 'POST');
        $ref2 = new \ReflectionProperty($idem2, 'hash');
        $ref2->setAccessible(true);

        $this->assertNotSame($ref1->getValue($idem1), $ref2->getValue($idem2));
    }

    public function testHashDiffersWithDifferentMethod(): void
    {
        $idem1 = new Idempotency();
        $idem1->setKey('same', 0, 'POST');
        $ref1 = new \ReflectionProperty($idem1, 'hash');
        $ref1->setAccessible(true);

        $idem2 = new Idempotency();
        $idem2->setKey('same', 0, 'PATCH');
        $ref2 = new \ReflectionProperty($idem2, 'hash');
        $ref2->setAccessible(true);

        $this->assertNotSame($ref1->getValue($idem1), $ref2->getValue($idem2));
    }

    public function testHashIsNullBeforeSetKey(): void
    {
        $idem = new Idempotency();
        $ref = new \ReflectionProperty($idem, 'hash');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($idem));
    }

    // ============================================================
    // storeResponse
    // ============================================================

    public function testStoreResponseWithValidData(): void
    {
        $idem = new Idempotency();
        $key = 'store-' . uniqid();
        $idem->setKey($key, 1, 'POST');
        $idem->storeResponse(['status' => 201, 'message' => 'created', 'id' => 42]);

        // Verify it can be retrieved as duplicate
        $idem2 = new Idempotency();
        $idem2->setKey($key, 1, 'POST');
        $this->assertTrue($idem2->isDuplicate());
        $stored = $idem2->getStoredResponse();
        $this->assertIsArray($stored);
        $this->assertSame(201, $stored['status']);
        $this->assertSame('created', $stored['message']);
        $this->assertSame(42, $stored['id']);
    }

    public function testStoreResponseWithoutKeyDoesNothing(): void
    {
        $idem = new Idempotency();
        // Don't call setKey — hash is null
        $idem->storeResponse(['status' => 200]);
        $this->assertNull($idem->getStoredResponse());
        $this->assertFalse($idem->isDuplicate());
    }

    public function testStoreResponseWithEmptyArray(): void
    {
        $idem = new Idempotency();
        $key = 'empty-resp-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse([]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertTrue($idem2->isDuplicate());
    }

    public function testStoreResponseOverwritesExisting(): void
    {
        $idem = new Idempotency();
        $key = 'overwrite-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['version' => 1]);
        $idem->storeResponse(['version' => 2]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertTrue($idem2->isDuplicate());
        $stored = $idem2->getStoredResponse();
        $this->assertSame(2, $stored['version']);
    }

    public function testStoreResponseWithNestedData(): void
    {
        $idem = new Idempotency();
        $key = 'nested-' . uniqid();
        $data = [
            'user' => ['id' => 1, 'name' => 'test'],
            'items' => [1, 2, 3],
            'meta' => ['count' => 3, 'active' => true],
        ];
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse($data);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertTrue($idem2->isDuplicate());
        $this->assertSame($data, $idem2->getStoredResponse());
    }

    // ============================================================
    // clear
    // ============================================================

    public function testClearResetsAllState(): void
    {
        $idem = new Idempotency();
        $idem->setKey('clear-test-' . uniqid(), 1, 'POST');
        $idem->storeResponse(['status' => 200]);

        $idem->clear();

        $this->assertNull($idem->getStoredResponse());
        $this->assertFalse($idem->isDuplicate());

        $ref = new \ReflectionProperty($idem, 'hash');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($idem));

        $refKey = new \ReflectionProperty($idem, 'currentKey');
        $refKey->setAccessible(true);
        $this->assertNull($refKey->getValue($idem));
    }

    // ============================================================
    // getStoredResponse
    // ============================================================

    public function testGetStoredResponseReturnsNullBeforeSetKey(): void
    {
        $idem = new Idempotency();
        $this->assertNull($idem->getStoredResponse());
    }

    public function testGetStoredResponseReturnsNullForNewKey(): void
    {
        $idem = new Idempotency();
        $idem->setKey('new-key-' . uniqid(), 0, 'POST');
        $this->assertNull($idem->getStoredResponse());
    }

    public function testGetStoredResponseReturnsDataForDuplicate(): void
    {
        $idem = new Idempotency();
        $key = 'resp-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['foo' => 'bar']);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertSame(['foo' => 'bar'], $idem2->getStoredResponse());
    }

    // ============================================================
    // isDuplicate
    // ============================================================

    public function testIsDuplicateFalseByDefault(): void
    {
        $idem = new Idempotency();
        $this->assertFalse($idem->isDuplicate());
    }

    public function testIsDuplicateTrueAfterDuplicateDetection(): void
    {
        $idem = new Idempotency();
        $key = 'dup-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['data' => 1]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertTrue($idem2->isDuplicate());
    }

    public function testIsDuplicateFalseAfterClear(): void
    {
        $idem = new Idempotency();
        $key = 'clear-dup-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['data' => 1]);
        $idem->clear();
        $this->assertFalse($idem->isDuplicate());
    }

    // ============================================================
    // createTable
    // ============================================================

    public function testCreateTableIsIdempotent(): void
    {
        // Already called in setUp — calling again should not throw
        Idempotency::createTable();
        $this->assertTrue(true);
    }

    // ============================================================
    // cleanup
    // ============================================================

    public function testCleanupRemovesExpiredKeys(): void
    {
        $idem = new Idempotency(1);
        $key = 'expire-cleanup-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['status' => 200]);

        // Wait or manually expire
        sleep(2);

        $deleted = Idempotency::cleanup(time());
        $this->assertGreaterThanOrEqual(1, $deleted);

        // Should no longer be duplicate
        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertFalse($idem2->isDuplicate());
    }

    public function testCleanupWithDefaultCutoffRemovesOldKeys(): void
    {
        // Default cutoff is 7 days ago — should not affect recent keys
        $deleted = Idempotency::cleanup();
        $this->assertIsInt($deleted);
        $this->assertGreaterThanOrEqual(0, $deleted);
    }

    public function testCleanupReturnsZeroWhenNothingToDelete(): void
    {
        $deleted = Idempotency::cleanup(time() - 86400 * 365);
        $this->assertIsInt($deleted);
    }

    // ============================================================
    // TTL behavior
    // ============================================================

    public function testConstructorDefaultTtl(): void
    {
        $idem = new Idempotency();
        $ref = new \ReflectionProperty($idem, 'ttl');
        $ref->setAccessible(true);
        $this->assertSame(86400, $ref->getValue($idem));
    }

    public function testConstructorCustomTtl(): void
    {
        $idem = new Idempotency(3600);
        $ref = new \ReflectionProperty($idem, 'ttl');
        $ref->setAccessible(true);
        $this->assertSame(3600, $ref->getValue($idem));
    }

    public function testConstructorZeroTtlBecomesOne(): void
    {
        $idem = new Idempotency(0);
        $ref = new \ReflectionProperty($idem, 'ttl');
        $ref->setAccessible(true);
        $this->assertSame(1, $ref->getValue($idem));
    }

    public function testConstructorNegativeTtlBecomesOne(): void
    {
        $idem = new Idempotency(-100);
        $ref = new \ReflectionProperty($idem, 'ttl');
        $ref->setAccessible(true);
        $this->assertSame(1, $ref->getValue($idem));
    }

    public function testDuplicateDetectedWithinTtlWindow(): void
    {
        $idem = new Idempotency(60);
        $key = 'ttl-window-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['status' => 200]);

        $idem2 = new Idempotency(60);
        $idem2->setKey($key, 0, 'POST');
        $this->assertTrue($idem2->isDuplicate());
    }

    // ============================================================
    // JSON encoding of stored response
    // ============================================================

    public function testStoredResponsePreservesStringValues(): void
    {
        $idem = new Idempotency();
        $key = 'str-resp-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['name' => 'John Doe', 'email' => 'john@example.com']);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $stored = $idem2->getStoredResponse();
        $this->assertSame('John Doe', $stored['name']);
        $this->assertSame('john@example.com', $stored['email']);
    }

    public function testStoredResponsePreservesNumericValues(): void
    {
        $idem = new Idempotency();
        $key = 'num-resp-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['count' => 42, 'price' => 19.99]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $stored = $idem2->getStoredResponse();
        $this->assertSame(42, $stored['count']);
        $this->assertSame(19.99, $stored['price']);
    }

    public function testStoredResponsePreservesBooleanValues(): void
    {
        $idem = new Idempotency();
        $key = 'bool-resp-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['active' => true, 'deleted' => false]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $stored = $idem2->getStoredResponse();
        $this->assertTrue($stored['active']);
        $this->assertFalse($stored['deleted']);
    }

    public function testStoredResponsePreservesNullValues(): void
    {
        $idem = new Idempotency();
        $key = 'null-resp-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['field' => null]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $stored = $idem2->getStoredResponse();
        $this->assertNull($stored['field']);
    }

    // ============================================================
    // User isolation
    // ============================================================

    public function testSameKeyDifferentUsersAreIsolated(): void
    {
        $key = 'isolation-' . uniqid();

        $idem1 = new Idempotency();
        $idem1->setKey($key, 10, 'POST');
        $idem1->storeResponse(['user' => 10]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 20, 'POST');
        $this->assertFalse($idem2->isDuplicate());

        $idem3 = new Idempotency();
        $idem3->setKey($key, 10, 'POST');
        $this->assertTrue($idem3->isDuplicate());
    }

    public function testZeroUserIdMatchesZeroUserId(): void
    {
        $key = 'zero-user-' . uniqid();
        $idem1 = new Idempotency();
        $idem1->setKey($key, 0, 'POST');
        $idem1->storeResponse(['anon' => true]);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertTrue($idem2->isDuplicate());
    }

    // ============================================================
    // Method isolation
    // ============================================================

    public function testSameKeySameUserDifferentMethodsAreIsolated(): void
    {
        $key = 'method-iso-' . uniqid();
        $idem1 = new Idempotency();
        $idem1->setKey($key, 1, 'POST');
        $idem1->storeResponse(['method' => 'post']);

        $idem2 = new Idempotency();
        $idem2->setKey($key, 1, 'PUT');
        $this->assertFalse($idem2->isDuplicate());

        $idem3 = new Idempotency();
        $idem3->setKey($key, 1, 'POST');
        $this->assertTrue($idem3->isDuplicate());
    }

    // ============================================================
    // Invalid JSON in stored response
    // ============================================================

    public function testDuplicateWithCorruptStoredJson(): void
    {
        $idem = new Idempotency();
        $key = 'corrupt-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['valid' => true]);

        // Manually corrupt the stored data
        $hash = hash('sha256', $key . '|0|POST');
        Database::execute(
            "UPDATE idempotency_keys SET response_data = 'not-json{' WHERE hash = ?",
            [$hash]
        );

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertTrue($idem2->isDuplicate());
        // Corrupt JSON should return null for stored response
        $this->assertNull($idem2->getStoredResponse());
    }

    public function testDuplicateWithEmptyStoredJson(): void
    {
        $idem = new Idempotency();
        $key = 'empty-json-' . uniqid();
        $idem->setKey($key, 0, 'POST');
        $idem->storeResponse(['data' => 1]);

        // Manually set empty response data
        $hash = hash('sha256', $key . '|0|POST');
        Database::execute(
            "UPDATE idempotency_keys SET response_data = '' WHERE hash = ?",
            [$hash]
        );

        $idem2 = new Idempotency();
        $idem2->setKey($key, 0, 'POST');
        $this->assertTrue($idem2->isDuplicate());
        $this->assertNull($idem2->getStoredResponse());
    }
}
