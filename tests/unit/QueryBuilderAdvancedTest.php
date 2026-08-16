<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * QueryBuilder advanced coverage — joins variants, having variants, date clauses,
 * chunking, pluck, upsert, bulk update/delete, cursor pagination, misc chainables.
 */
final class QueryBuilderAdvancedTest extends TestCase
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
        Database::table('users')->insert(['name' => 'Alice', 'email' => 'alice@test.com', 'age' => 30, 'role' => 'admin', 'created_at' => '2024-01-10 10:00:00']);
        Database::table('users')->insert(['name' => 'Bob', 'email' => 'bob@test.com', 'age' => 25, 'role' => 'user', 'created_at' => '2024-02-15 11:00:00']);
        Database::table('users')->insert(['name' => 'Carol', 'email' => 'carol@test.com', 'age' => 35, 'role' => 'user', 'created_at' => '2024-03-20 12:00:00']);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testWhereColumn(): void
    {
        $rows = Database::table('users')->whereColumn('name', 'email')->get();
        $this->assertIsArray($rows);
    }

    public function testWhereColumnShortForm(): void
    {
        Database::table('users')->whereColumn('age', 'age')->get();
        $rows = Database::table('users')->whereColumn('age', 'age')->get();
        $this->assertCount(3, $rows);
    }

    public function testWhereDate(): void
    {
        $sql = Database::table('users')->whereDate('created_at', '2024-02-15')->toSql();
        $this->assertStringContainsString('DATE', $sql);
    }

    public function testWhereMonth(): void
    {
        $sql = Database::table('users')->whereMonth('created_at', '2')->toSql();
        $this->assertStringContainsString('MONTH', $sql);
    }

    public function testWhereDay(): void
    {
        $sql = Database::table('users')->whereDay('created_at', '15')->toSql();
        $this->assertStringContainsString('DAY', $sql);
    }

    public function testWhereYear(): void
    {
        $sql = Database::table('users')->whereYear('created_at', '2024')->toSql();
        $this->assertStringContainsString('YEAR', $sql);
    }

    public function testWhereTime(): void
    {
        $sql = Database::table('users')->whereTime('created_at', '10:00:00')->toSql();
        $this->assertStringContainsString('TIME', $sql);
    }

    public function testWhereRawAndOrWhereIn(): void
    {
        $rows = Database::table('users')
            ->where('role', 'user')
            ->orWhereIn('name', ['Alice', 'Zoe'])
            ->get();
        $this->assertCount(3, $rows);
    }

    public function testWhenAndUnless(): void
    {
        $rows = Database::table('users')
            ->when(true, fn ($q) => $q->where('age', '>', 28))
            ->unless(true, fn ($q) => $q->where('age', '>', 100))
            ->get();
        $this->assertCount(2, $rows);

        $rows2 = Database::table('users')
            ->when(false, fn ($q) => $q->where('id', 999), fn ($q) => $q->where('age', '<', 26))
            ->get();
        $this->assertCount(1, $rows2);
    }

    public function testRightJoin(): void
    {
        Database::table('orders')->insert(['user_id' => 1, 'total' => 100]);
        $rows = Database::table('orders')
            ->rightJoin('users', 'orders.user_id', '=', 'users.id')
            ->select('users.name')
            ->get();
        $this->assertNotEmpty($rows);
    }

    public function testCrossJoin(): void
    {
        $sql = Database::table('users')->crossJoin('orders')->toSql();
        $this->assertStringContainsString('CROSS JOIN', $sql);
    }

    public function testGroupByRawAndHavingRaw(): void
    {
        Database::table('orders')->insert(['user_id' => 1, 'total' => 100]);
        Database::table('orders')->insert(['user_id' => 2, 'total' => 50]);
        $rows = Database::table('orders')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('total > ?', [10])
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testOrHaving(): void
    {
        Database::table('orders')->insert(['user_id' => 1, 'total' => 100]);
        $rows = Database::table('orders')
            ->select('user_id')
            ->groupBy('user_id')
            ->having('total', '<', 0)
            ->orHaving('total', '>', 0)
            ->get();
        $this->assertNotEmpty($rows);
    }

    public function testOrderByRawAndReorder(): void
    {
        $sql = Database::table('users')->orderByRaw('age DESC')->toSql();
        $this->assertStringContainsString('age DESC', $sql);
        $reordered = Database::table('users')->orderByRaw('age DESC')->reorder()->get();
        $this->assertCount(3, $reordered);
    }

    public function testLatestOldest(): void
    {
        $latest = Database::table('users')->latest('created_at')->first();
        $this->assertSame('Carol', $latest['name']);
        $oldest = Database::table('users')->oldest('created_at')->first();
        $this->assertSame('Alice', $oldest['name']);
    }

    public function testOrderByDescAlias(): void
    {
        $rows = Database::table('users')->orderByDesc('age')->get();
        $this->assertSame('Carol', $rows[0]['name']);
    }

    public function testLockForUpdateSharedLock(): void
    {
        $rows = Database::table('users')->lockForUpdate()->get();
        $this->assertCount(3, $rows);
        $rows2 = Database::table('users')->sharedLock()->get();
        $this->assertCount(3, $rows2);
    }

    public function testCursor(): void
    {
        $names = [];
        foreach (Database::table('users')->orderBy('id')->cursor() as $row) {
            $names[] = $row['name'];
        }
        $this->assertSame(['Alice', 'Bob', 'Carol'], $names);
    }

    public function testWhereBetween(): void
    {
        $sql = Database::table('users')->whereBetween('age', [20, 30])->toSql();
        $this->assertStringContainsString('BETWEEN', $sql);
    }

    public function testWhereNotBetween(): void
    {
        $sql = Database::table('users')->whereNotBetween('age', [20, 30])->toSql();
        $this->assertStringContainsString('NOT BETWEEN', $sql);
    }

    public function testWhereNullNotNull(): void
    {
        Database::table('users')->insert(['name' => 'NullUser', 'email' => null]);
        $nulls = Database::table('users')->whereNull('email')->get();
        $this->assertCount(1, $nulls);
        $notNull = Database::table('users')->whereNotNull('email')->get();
        $this->assertCount(3, $notNull);
        $orNull = Database::table('users')->where('age', '>', 100)->orWhereNull('email')->get();
        $this->assertCount(1, $orNull);
        $orNotNull = Database::table('users')->where('age', '>', 100)->orWhereNotNull('email')->get();
        $this->assertCount(3, $orNotNull);
    }

    public function testPluck(): void
    {
        $names = Database::table('users')->orderBy('id')->pluck('name');
        $this->assertSame(['Alice', 'Bob', 'Carol'], $names);
        $byId = Database::table('users')->pluck('name', 'id');
        $this->assertSame('Alice', $byId['1']);
    }

    public function testValue(): void
    {
        $name = Database::table('users')->where('name', '=', 'Bob')->value('email');
        $this->assertSame('bob@test.com', $name);
        $missing = Database::table('users')->where('name', '=', 'Nobody')->value('email');
        $this->assertNull($missing);
    }

    public function testChunk(): void
    {
        $all = [];
        Database::table('users')->orderBy('id')->chunk(2, function ($rows) use (&$all) {
            foreach ($rows as $row) {
                $all[] = $row['name'];
            }
        });
        $this->assertSame(['Alice', 'Bob', 'Carol'], $all);
    }

    public function testChunkById(): void
    {
        $ids = [];
        Database::table('users')->orderBy('id')->chunkById(2, function ($rows) use (&$ids) {
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
            }
        });
        $this->assertSame([1, 2, 3], $ids);
    }

    public function testDoesntExist(): void
    {
        $this->assertTrue(Database::table('users')->where('name', '=', 'Nobody')->doesntExist());
        $this->assertFalse(Database::table('users')->where('name', '=', 'Alice')->doesntExist());
    }

    public function testSetPrimaryKey(): void
    {
        $row = Database::table('users')->setPrimaryKey('id')->where('name', '=', 'Bob')->first();
        $this->assertSame('Bob', $row['name']);
    }

    public function testInRandomOrder(): void
    {
        $rows = Database::table('users')->inRandomOrder(42)->get();
        $this->assertCount(3, $rows);
    }

    public function testDumpOutputsSql(): void
    {
        ob_start();
        Database::table('users')->where('age', '>', 18)->dump();
        $output = ob_get_clean();
        $this->assertStringContainsString('SELECT', $output);
    }

    public function testUpsert(): void
    {
        Database::execute('CREATE TABLE unique_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE,
            age INTEGER DEFAULT 0
        )');
        Database::table('unique_users')->insert(['email' => 'dave@test.com', 'age' => 40]);
        $affected = Database::table('unique_users')->upsert(
            ['email' => 'dave@test.com', 'age' => 41],
            ['email']
        );
        $this->assertGreaterThanOrEqual(0, $affected);
    }

    public function testUpdateWhereIn(): void
    {
        $affected = Database::table('users')->updateWhereIn([1, 2], ['role' => 'editor']);
        $this->assertSame(2, $affected);
        $rows = Database::table('users')->where('role', 'editor')->get();
        $this->assertCount(2, $rows);
    }

    public function testDeleteWhereIn(): void
    {
        $affected = Database::table('users')->deleteWhereIn([2, 3]);
        $this->assertSame(2, $affected);
        $this->assertSame(1, Database::table('users')->count());
    }

    public function testInsertMany(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['name' => "Person$i", 'email' => "p$i@test.com", 'age' => 20 + $i];
        }
        $inserted = Database::table('users')->insertMany($rows);
        $this->assertSame(5, $inserted);
        $this->assertSame(8, Database::table('users')->count());
    }

    public function testCursorPaginate(): void
    {
        $page = Database::table('users')->orderBy('created_at')->cursorPaginate(2, null, 'asc');
        $this->assertCount(2, $page['data']);
        $this->assertTrue($page['meta']['has_more']);
        $this->assertNotNull($page['next_cursor']);

        $page2 = Database::table('users')->orderBy('created_at')->cursorPaginate(2, $page['next_cursor'], 'asc');
        $this->assertNotEmpty($page2['data']);
    }

    public function testCursorPaginateDesc(): void
    {
        $page = Database::table('users')->orderBy('created_at')->cursorPaginate(2, null, 'desc');
        $this->assertCount(2, $page['data']);
        $this->assertSame('DESC', $page['meta']['order']);
    }

    public function testCacheTtl(): void
    {
        $rows = Database::table('users')->cache(30)->get();
        $this->assertCount(3, $rows);
    }
}
