<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\DB\QueryBuilder;
use Siro\Core\Env;

/**
 * Grouped WHERE: where(fn ($q) => ...) compiles to parenthesised
 * conditions with unique placeholders across the group boundary.
 */
final class QueryBuilderGroupedWhereTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testSimpleGroup(): void
    {
        [$sql, $bindings] = (new QueryBuilder('users'))
            ->where(fn ($q) => $q->where('a', 1)->orWhere('b', 2))
            ->toCompiled();
        $this->assertStringContainsString('WHERE (`a` = :w_0 OR `b` = :w_1)', $sql);
        $this->assertSame(['w_0' => 1, 'w_1' => 2], $bindings);
    }

    public function testOrGroup(): void
    {
        [$sql, $bindings] = (new QueryBuilder('users'))
            ->where('status', 'active')
            ->orWhere(fn ($q) => $q->where('a', 1)->where('b', 2))
            ->toCompiled();
        $this->assertStringContainsString('WHERE `status` = :w_0 OR (`a` = :w_1 AND `b` = :w_2)', $sql);
        $this->assertSame(['w_0' => 'active', 'w_1' => 1, 'w_2' => 2], $bindings);
    }

    public function testNestedGroup(): void
    {
        [$sql, $bindings] = (new QueryBuilder('users'))
            ->where(fn ($q) => $q->where('a', 1)->orWhere(fn ($q2) => $q2->where('b', 2)->where('c', 3)))
            ->toCompiled();
        $this->assertStringContainsString('WHERE (`a` = :w_0 OR (`b` = :w_1 AND `c` = :w_2))', $sql);
        $this->assertSame(['w_0' => 1, 'w_1' => 2, 'w_2' => 3], $bindings);
    }

    public function testGroupWithWhereInAndWhereNull(): void
    {
        [$sql, $bindings] = (new QueryBuilder('users'))
            ->where(fn ($q) => $q->whereIn('role', ['admin', 'mod'])->whereNull('deleted_at'))
            ->toCompiled();
        $this->assertStringContainsString('WHERE (`role` IN (:wi_0_0, :wi_0_1) AND `deleted_at` IS NULL)', $sql);
        $this->assertSame(['wi_0_0' => 'admin', 'wi_0_1' => 'mod'], $bindings);
    }

    public function testEmptyGroup(): void
    {
        [$sql] = (new QueryBuilder('users'))
            ->where(fn ($q) => $q)
            ->toCompiled();
        $this->assertStringContainsString('WHERE (1 = 1)', $sql);
    }

    public function testPlaceholdersStayUniqueAcrossGroupBoundary(): void
    {
        [$sql, $bindings] = (new QueryBuilder('users'))
            ->where('x', 1)
            ->where(fn ($q) => $q->where('a', 2)->orWhere('b', 3))
            ->where('y', 4)
            ->toCompiled();
        $this->assertStringContainsString('WHERE `x` = :w_0 AND (`a` = :w_1 OR `b` = :w_2) AND `y` = :w_3', $sql);
        $this->assertSame(['w_0' => 1, 'w_1' => 2, 'w_2' => 3, 'w_3' => 4], $bindings);
    }

    public function testGroupedWhereWithOperatorThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not accept an operator or value');
        (new QueryBuilder('users'))->where(fn ($q) => $q->where('a', 1), '=', 2);
    }

    public function testGroupedWhereInUpdateAndDelete(): void
    {
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE gw_items (id INTEGER PRIMARY KEY AUTOINCREMENT, a INTEGER, b INTEGER)');
        Database::table('gw_items')->insert(['a' => 1, 'b' => 1]);
        Database::table('gw_items')->insert(['a' => 1, 'b' => 2]);
        Database::table('gw_items')->insert(['a' => 2, 'b' => 2]);

        $affected = (new QueryBuilder('gw_items'))
            ->where(fn ($q) => $q->where('a', 1)->where('b', 2))
            ->update(['b' => 9]);
        $this->assertSame(1, $affected);

        $deleted = (new QueryBuilder('gw_items'))
            ->where(fn ($q) => $q->where('a', 2)->orWhere('b', 9))
            ->delete();
        $this->assertSame(2, $deleted);
        $this->assertSame(1, Database::table('gw_items')->count());
    }
}
