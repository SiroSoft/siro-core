<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;

/**
 * Branch coverage for Database facade (DatabaseInstance).
 */
final class DatabaseFacadeMutationTest extends TestCase
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
        Database::execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testExecAndSelect(): void
    {
        Database::execute("INSERT INTO t (name) VALUES ('x')");
        $rows = Database::select('SELECT * FROM t');
        $this->assertCount(1, $rows);
    }

    public function testFirst(): void
    {
        Database::execute("INSERT INTO t (name) VALUES ('first')");
        $row = Database::first('SELECT * FROM t');
        $this->assertSame('first', $row['name']);
    }

    public function testExecuteReturnsAffected(): void
    {
        Database::execute("INSERT INTO t (name) VALUES ('a'), ('b')");
        $affected = Database::execute('DELETE FROM t');
        $this->assertSame(2, $affected);
    }

    public function testTableQueryBuilder(): void
    {
        Database::table('t')->insert(['name' => 'q']);
        $rows = Database::table('t')->get();
        $this->assertCount(1, $rows);
    }

    public function testDefaultConnection(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:'], 'alt');
        Database::default('alt');
        Database::execute('CREATE TABLE t2 (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $this->assertNotEmpty(Database::connections());
    }

    public function testConnectionNames(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:'], 'second');
        $conns = Database::connections();
        $this->assertCount(2, $conns);
    }

    public function testQueryCapture(): void
    {
        Database::enableQueryCapture(true);
        Database::select('SELECT 1');
        $queries = Database::getCapturedQueries();
        $this->assertNotEmpty($queries);
        Database::resetCapturedQueries();
        $this->assertSame([], Database::getCapturedQueries());
        Database::enableQueryCapture(false);
    }

    public function testConnectionReturnsPdo(): void
    {
        $pdo = Database::connection();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }
}
