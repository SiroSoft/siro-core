<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\DB\QueryBuilder;
use Siro\Core\Env;

/**
 * from() alias, fromSub() and selectSub() — JOIN/subquery without Models.
 */
final class QueryBuilderSubQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        Database::execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');
        Database::execute("INSERT INTO users (name, age) VALUES ('Alice', 30), ('Bob', 25), ('Carol', 35)");
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testFromIsTableAlias(): void
    {
        $qb = (new QueryBuilder('users'))->from('users')->select('name');
        [$sql] = $qb->toCompiled();
        $this->assertStringContainsString('FROM', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testFromSubWithBuilder(): void
    {
        $qb = (new QueryBuilder('x'))
            ->fromSub(
                (new QueryBuilder('users'))->select('id', 'name')->where('age', '>', 26),
                'adults'
            )
            ->select('name');

        [$sql, $bindings] = $qb->toCompiled();
        $this->assertStringContainsString('FROM (SELECT', $sql);
        $this->assertStringContainsString('adults', $sql);
        // Inner binding renamed to avoid collision with outer counters.
        $this->assertArrayNotHasKey('w_0', $bindings);

        $rows = $qb->get();
        $names = array_column($rows, 'name');
        sort($names);
        $this->assertSame(['Alice', 'Carol'], $names);
    }

    public function testFromSubWithClosure(): void
    {
        $rows = (new QueryBuilder('x'))
            ->fromSub(fn (QueryBuilder $q): QueryBuilder => $q->from('users')->where('age', '<', 30), 'young')
            ->select('name')
            ->get();

        $this->assertSame([['name' => 'Bob']], $rows);
    }

    public function testFromSubWithRawString(): void
    {
        $rows = (new QueryBuilder('x'))
            ->fromSub('SELECT id, name FROM users WHERE age > 26', 'adults')
            ->select('name')
            ->orderBy('name')
            ->get();

        $this->assertSame([['name' => 'Alice'], ['name' => 'Carol']], $rows);
    }

    public function testSelectSubExecutes(): void
    {
        $rows = (new QueryBuilder('users'))
            ->select('name')
            ->selectSub(
                (new QueryBuilder('users'))->selectRaw('MAX(age)'),
                'max_age'
            )
            ->where('name', 'Alice')
            ->get();

        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame(35, (int) $rows[0]['max_age']);
    }

    public function testSelectSubBindingCollisionSafe(): void
    {
        // Outer and inner both generate w_0; merge must rename the inner one.
        $qb = (new QueryBuilder('users'))
            ->select('name')
            ->selectSub(
                (new QueryBuilder('users'))->selectRaw('MAX(age)')->where('age', '>', 20),
                'max_age'
            )
            ->where('age', '>', 26);

        [$sql, $bindings] = $qb->toCompiled();
        $this->assertSame(2, count($bindings));
        $this->assertStringContainsString(':sub0_w_0', $sql);
        $this->assertStringContainsString(':w_0', $sql);

        $rows = $qb->orderBy('name')->get();
        $this->assertCount(2, $rows);
    }

    public function testFromSubEmptyAliasThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new QueryBuilder('x'))->fromSub('SELECT 1', '');
    }

    public function testFromSubClosureWithoutFromThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new QueryBuilder('x'))->fromSub(fn (QueryBuilder $q): QueryBuilder => $q->where('a', 1), 's');
    }
}
