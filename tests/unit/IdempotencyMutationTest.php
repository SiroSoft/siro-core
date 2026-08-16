<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\Idempotency;
use Siro\Core\Database;

/**
 * Strong-assertion tests targeting mutations on Auth\Idempotency.
 * Exercises setKey/storeResponse/cleanup with a real SQLite database.
 */
final class IdempotencyMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        Idempotency::createTable();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testConstructorClampsTtlToMinimum(): void
    {
        $idempotency = new Idempotency(0);
        $idempotency->setKey('key-1', 1, 'POST');
        $idempotency->storeResponse(['ok' => true]);

        $row = Database::select(
            'SELECT expires_at, created_at FROM idempotency_keys WHERE hash = ?',
            [hash('sha256', 'key-1|1|POST')]
        );
        $this->assertNotEmpty($row);
        $this->assertSame((int) $row[0]['created_at'] + 1, (int) $row[0]['expires_at']);
    }

    public function testTrimsKeyBeforeHash(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('  spaced-key  ', 1, 'POST');
        $this->assertFalse($idempotency->isDuplicate());
        $idempotency->storeResponse(['ok' => true]);

        $this->assertNotEmpty(Database::select(
            'SELECT id FROM idempotency_keys WHERE hash = ?',
            [hash('sha256', 'spaced-key|1|POST')]
        ));
    }

    public function testClampsNegativeUserIdToZero(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('key-neg', -5, 'POST');
        $idempotency->storeResponse(['ok' => true]);

        $this->assertNotEmpty(Database::select(
            'SELECT id FROM idempotency_keys WHERE hash = ?',
            [hash('sha256', 'key-neg|0|POST')]
        ));
    }

    public function testStoreResponseWithoutKeyDoesNothing(): void
    {
        $idempotency = new Idempotency();
        $idempotency->storeResponse(['ok' => true]);
        $this->assertSame(0, Database::execute(
            'SELECT COUNT(*) FROM idempotency_keys'
        ));
    }

    public function testDuplicateDetection(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('dup-key', 1, 'POST');
        $idempotency->storeResponse(['status' => 201, 'id' => 99]);

        $second = new Idempotency();
        $second->setKey('dup-key', 1, 'POST');
        $this->assertTrue($second->isDuplicate());
        $stored = $second->getStoredResponse();
        $this->assertSame(201, $stored['status']);
        $this->assertSame(99, $stored['id']);
    }

    public function testDifferentMethodIsNotDuplicate(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('same-key', 1, 'POST');
        $idempotency->storeResponse(['ok' => true]);

        $second = new Idempotency();
        $second->setKey('same-key', 1, 'PUT');
        $this->assertFalse($second->isDuplicate());
    }

    public function testDifferentUserIsNotDuplicate(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('shared-key', 1, 'POST');
        $idempotency->storeResponse(['ok' => true]);

        $second = new Idempotency();
        $second->setKey('shared-key', 2, 'POST');
        $this->assertFalse($second->isDuplicate());
    }

    public function testExpiredKeyIsNotDuplicate(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('exp-key', 1, 'POST');
        $idempotency->storeResponse(['ok' => true]);

        $hash = hash('sha256', 'exp-key|1|POST');
        Database::execute(
            'UPDATE idempotency_keys SET expires_at = ? WHERE hash = ?',
            [time() - 10, $hash]
        );

        $second = new Idempotency();
        $second->setKey('exp-key', 1, 'POST');
        $this->assertFalse($second->isDuplicate());
        $this->assertNull($second->getStoredResponse());
    }

    public function testCorruptResponseDataYieldsNullStoredResponse(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('corrupt-key', 1, 'POST');
        $hash = hash('sha256', 'corrupt-key|1|POST');
        Database::execute(
            "INSERT INTO idempotency_keys (hash, idempotency_key, user_id, response_data, created_at, expires_at)
             VALUES (?, ?, 1, 'not-json{', ?, ?)",
            [$hash, 'corrupt-key', time(), time() + 1000]
        );

        $second = new Idempotency();
        $second->setKey('corrupt-key', 1, 'POST');
        $this->assertTrue($second->isDuplicate());
        $this->assertNull($second->getStoredResponse());
    }

    public function testCleanupDeletesExpired(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('old-key', 1, 'POST');
        $idempotency->storeResponse(['ok' => true]);
        $idempotency->setKey('new-key', 1, 'POST');
        $idempotency->storeResponse(['ok' => true]);

        $hashOld = hash('sha256', 'old-key|1|POST');
        $hashNew = hash('sha256', 'new-key|1|POST');
        Database::execute(
            'UPDATE idempotency_keys SET expires_at = ? WHERE hash = ?',
            [time() - 100, $hashOld]
        );

        $deleted = Idempotency::cleanup(time() - 50);
        $this->assertSame(1, $deleted);
        $this->assertEmpty(Database::select(
            'SELECT id FROM idempotency_keys WHERE hash = ?',
            [$hashOld]
        ));
        $this->assertNotEmpty(Database::select(
            'SELECT id FROM idempotency_keys WHERE hash = ?',
            [$hashNew]
        ));
    }

    public function testCleanupDefaultCutoff(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('ancient-key', 1, 'POST');
        $idempotency->storeResponse(['ok' => true]);

        $hash = hash('sha256', 'ancient-key|1|POST');
        Database::execute(
            'UPDATE idempotency_keys SET expires_at = ? WHERE hash = ?',
            [time() - (8 * 86400), $hash]
        );

        $deleted = Idempotency::cleanup();
        $this->assertSame(1, $deleted);
    }

    public function testCreateTableIsIdempotent(): void
    {
        Idempotency::createTable();
        Idempotency::createTable();
        $this->assertTrue(true);
    }

    public function testStoreResponseUpsertsExistingKey(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('upsert-key', 1, 'POST');
        $idempotency->storeResponse(['status' => 200]);
        $idempotency->storeResponse(['status' => 202]);

        $row = Database::select(
            'SELECT response_data FROM idempotency_keys WHERE hash = ?',
            [hash('sha256', 'upsert-key|1|POST')]
        );
        $this->assertCount(1, $row);
        $this->assertStringContainsString('202', (string) $row[0]['response_data']);
    }
}
