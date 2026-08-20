<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Extra Database branches: exec, transaction, cache, writeConnection.
 */
final class DatabaseInstanceMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Cache::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE dit (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        Cache::reset();
        parent::tearDown();
    }

    public function testExec(): void
    {
        Database::execute("INSERT INTO dit (name) VALUES ('x')");
        $this->assertSame(1, Database::select('SELECT COUNT(*) c FROM dit')[0]['c']);
    }

    public function testTransactionCommit(): void
    {
        $result = Database::transaction(function () {
            Database::execute("INSERT INTO dit (name) VALUES ('t1')");
            Database::execute("INSERT INTO dit (name) VALUES ('t2')");
            return 'done';
        });
        $this->assertSame('done', $result);
        $this->assertSame(2, Database::select('SELECT COUNT(*) c FROM dit')[0]['c']);
    }

    public function testTransactionRollback(): void
    {
        try {
            Database::transaction(function () {
                Database::execute("INSERT INTO dit (name) VALUES ('t1')");
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
        }
        $this->assertSame(0, Database::select('SELECT COUNT(*) c FROM dit')[0]['c']);
    }

    public function testWriteConnection(): void
    {
        $pdo = Database::writeConnection();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testSelectCached(): void
    {
        $rows = Database::selectCached('SELECT * FROM dit', [], 60);
        $this->assertIsArray($rows);
        // second call from cache
        $rows2 = Database::selectCached('SELECT * FROM dit', [], 60);
        $this->assertSame($rows, $rows2);
    }

    public function testCacheTtlOnBuilder(): void
    {
        Database::table('dit')->cache(30)->get();
        $this->assertTrue(true);
    }

    public function testPurgeSingleConnection(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:'], 'alt');
        Database::connection('alt');
        Database::purge('alt');
        $this->assertTrue(true);
    }
}
