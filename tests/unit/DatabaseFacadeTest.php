<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Database facade + DatabaseInstance tests — configure, connection, select,
 * first, execute, transaction, table builder, purge, query capture.
 */
final class DatabaseFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        Database::execute("INSERT INTO users (name) VALUES ('A'), ('B'), ('C')");
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testSelectReturnsRows(): void
    {
        $rows = Database::select('SELECT * FROM users ORDER BY id');
        $this->assertCount(3, $rows);
        $this->assertSame('A', $rows[0]['name']);
    }

    public function testSelectWithParams(): void
    {
        $rows = Database::select('SELECT * FROM users WHERE id = :id', ['id' => 2]);
        $this->assertCount(1, $rows);
        $this->assertSame('B', $rows[0]['name']);
    }

    public function testFirstReturnsRow(): void
    {
        $row = Database::first('SELECT * FROM users WHERE name = :n', ['n' => 'C']);
        $this->assertSame(3, $row['id']);
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $this->assertNull(Database::first('SELECT * FROM users WHERE name = :n', ['n' => 'Z']));
    }

    public function testExecuteReturnsAffected(): void
    {
        $affected = Database::execute('UPDATE users SET name = :n WHERE id = :id', ['n' => 'Renamed', 'id' => 1]);
        $this->assertSame(1, $affected);
        $row = Database::first('SELECT name FROM users WHERE id = 1');
        $this->assertSame('Renamed', $row['name']);
    }

    public function testExecDdl(): void
    {
        $affected = Database::execute('CREATE TABLE temp_t (id INTEGER)');
        $this->assertGreaterThanOrEqual(0, $affected);
    }

    public function testTableBuilder(): void
    {
        $qb = Database::table('users');
        $this->assertInstanceOf(\Siro\Core\DB\QueryBuilder::class, $qb);
        $this->assertSame(3, $qb->count());
    }

    public function testTransactionCommit(): void
    {
        $result = Database::transaction(function () {
            Database::execute("INSERT INTO users (name) VALUES ('D')");
            return 'done';
        });
        $this->assertSame('done', $result);
        $this->assertSame(4, Database::table('users')->count());
    }

    public function testTransactionRollbackOnException(): void
    {
        try {
            Database::transaction(function () {
                Database::execute("INSERT INTO users (name) VALUES ('E')");
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('fail', $e->getMessage());
        }
        $this->assertSame(3, Database::table('users')->count(), 'insert should be rolled back');
    }

    public function testPurgeAll(): void
    {
        Database::purgeAll();
        // After purge, re-configure and it works
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
        ]);
        $this->assertNotNull(Database::connection());
    }

    public function testQueryCapture(): void
    {
        Database::resetCapturedQueries();
        Database::enableQueryCapture(true);
        Database::table('users')->get();
        $queries = Database::getCapturedQueries();
        $this->assertNotEmpty($queries);
        Database::enableQueryCapture(false);
        Database::resetCapturedQueries();
    }
}
