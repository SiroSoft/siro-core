<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * QueryBuilder real-database tests — sqlite in-memory.
 * Covers select/where/orderBy/groupBy/having/joins/aggregates/insert/update/
 * delete/paginate/exists/toSql + date/whereIn/whereNull builder clauses.
 */
final class QueryBuilderExecuteTest extends TestCase
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
        Database::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT,
            age INTEGER DEFAULT 0,
            role TEXT DEFAULT "user",
            created_at TEXT
        )');
        Database::execute('CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            total REAL DEFAULT 0,
            status TEXT DEFAULT "pending"
        )');
        Database::table('users')->insert(['name' => 'Alice', 'email' => 'alice@test.com', 'age' => 30, 'role' => 'admin']);
        Database::table('users')->insert(['name' => 'Bob', 'email' => 'bob@test.com', 'age' => 25, 'role' => 'user']);
        Database::table('users')->insert(['name' => 'Carol', 'email' => 'carol@test.com', 'age' => 35, 'role' => 'user']);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testGetAllRows(): void
    {
        $rows = Database::table('users')->get();
        $this->assertCount(3, $rows);
    }

    public function testSelectColumns(): void
    {
        $rows = Database::table('users')->select('id', 'name')->get();
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayNotHasKey('email', $rows[0]);
    }

    public function testWhere(): void
    {
        $rows = Database::table('users')->where('age', '>', 28)->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereSimpleValue(): void
    {
        $rows = Database::table('users')->where('role', 'admin')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testOrWhere(): void
    {
        $rows = Database::table('users')
            ->where('name', '=', 'Alice')
            ->orWhere('name', '=', 'Bob')
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereIn(): void
    {
        $rows = Database::table('users')->whereIn('name', ['Alice', 'Carol'])->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereNotIn(): void
    {
        $rows = Database::table('users')->whereNotIn('role', ['admin'])->get();
        $this->assertCount(2, $rows);
    }

    public function testOrderByDesc(): void
    {
        $rows = Database::table('users')->orderByDesc('age')->get();
        $this->assertSame('Carol', $rows[0]['name']);
    }

    public function testLimitOffset(): void
    {
        $rows = Database::table('users')->orderBy('id')->limit(2)->offset(1)->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
    }

    public function testFirst(): void
    {
        $row = Database::table('users')->where('name', '=', 'Bob')->first();
        $this->assertSame('bob@test.com', $row['email']);
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $row = Database::table('users')->where('name', '=', 'Nobody')->first();
        $this->assertNull($row);
    }

    public function testCountSumAvgMaxMin(): void
    {
        $this->assertSame(3, Database::table('users')->count());
        $this->assertSame(90, Database::table('users')->sum('age'));
        $this->assertSame(30, Database::table('users')->avg('age'));
        $this->assertSame(35, Database::table('users')->max('age'));
        $this->assertSame(25, Database::table('users')->min('age'));
    }

    public function testExists(): void
    {
        $this->assertTrue(Database::table('users')->where('name', '=', 'Alice')->exists());
        $this->assertFalse(Database::table('users')->where('name', '=', 'Nobody')->exists());
    }

    public function testInsertReturnsInsertId(): void
    {
        $result = Database::table('users')->insert(['name' => 'Dave', 'email' => 'dave@test.com', 'age' => 40]);
        $this->assertGreaterThan(0, $result, 'insert should return the new row id');
        $this->assertSame(4, Database::table('users')->count());
    }

    public function testInsertGetId(): void
    {
        $id = Database::table('users')->insertGetId(['name' => 'Eve', 'email' => 'eve@test.com', 'age' => 22]);
        $this->assertGreaterThan(0, $id);
    }

    public function testUpdate(): void
    {
        $affected = Database::table('users')->where('name', '=', 'Bob')->update(['role' => 'editor']);
        $this->assertSame(1, $affected);
        $row = Database::table('users')->where('name', '=', 'Bob')->first();
        $this->assertSame('editor', $row['role']);
    }

    public function testDelete(): void
    {
        $affected = Database::table('users')->where('name', '=', 'Bob')->delete();
        $this->assertSame(1, $affected);
        $this->assertSame(2, Database::table('users')->count());
    }

    public function testGroupByHaving(): void
    {
        Database::table('orders')->insert(['user_id' => 1, 'total' => 100, 'status' => 'paid']);
        Database::table('orders')->insert(['user_id' => 1, 'total' => 50, 'status' => 'paid']);
        Database::table('orders')->insert(['user_id' => 2, 'total' => 30, 'status' => 'pending']);
        $rows = Database::table('orders')
            ->select('user_id')
            ->groupBy('user_id')
            ->having('user_id', '>', 0)
            ->get();
        $this->assertNotEmpty($rows);
    }

    public function testJoin(): void
    {
        Database::table('orders')->insert(['user_id' => 1, 'total' => 100]);
        $rows = Database::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->select('orders.id', 'users.name')
            ->get();
        $this->assertNotEmpty($rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testPaginate(): void
    {
        $result = Database::table('users')->paginate(2, 1);
        $this->assertCount(2, $result['data']);
        $this->assertSame(3, $result['meta']['total'] ?? $result['total'] ?? 0);
    }

    public function testToSqlContainsTable(): void
    {
        $sql = Database::table('users')->where('age', '>', 18)->toSql();
        $this->assertStringContainsString('users', $sql);
    }

    public function testDistinct(): void
    {
        $rows = Database::table('users')->distinct()->select('role')->get();
        $this->assertCount(2, $rows);
    }
}
