<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Siro\Core\DB\DatabaseConnectionException;
use Siro\Core\DB\ForeignKey;
use Siro\Core\DB\JoinClause;
use Siro\Core\DB\SqlCompiler;

/**
 * Coverage tests for DB classes previously at 0%: JoinClause, ForeignKey,
 * DatabaseConnectionException.
 */
final class DBUncoveredMutationTest extends TestCase
{
    private function makeJoin(): JoinClause
    {
        return new JoinClause('left', 'orders', new SqlCompiler());
    }

    public function testJoinClauseOnAddsCondition(): void
    {
        $join = $this->makeJoin();
        $result = $join->on('users.id', '=', 'orders.user_id');
        $this->assertSame($join, $result);
        $this->assertCount(1, $join->conditions);
        $this->assertSame('users.id', $join->conditions[0]['first']);
        $this->assertSame('orders.user_id', $join->conditions[0]['second']);
        $this->assertSame('AND', $join->conditions[0]['boolean']);
    }

    public function testJoinClauseOrOn(): void
    {
        $join = $this->makeJoin();
        $join->orOn('a', '=', 'b');
        $this->assertCount(1, $join->conditions);
        $this->assertSame('OR', $join->conditions[0]['boolean']);
    }

    public function testJoinClauseWhereAddsBindings(): void
    {
        $join = $this->makeJoin();
        $join->where('orders.status', '=', 'active');
        $this->assertCount(1, $join->conditions);
        $this->assertSame('?', $join->conditions[0]['first']);
        $this->assertSame(['orders.status', 'active'], $join->conditions[0]['bindings']);
    }

    public function testJoinClauseNormalizesOperator(): void
    {
        $join = $this->makeJoin();
        $join->on('a', 'like', 'b');
        $this->assertSame('LIKE', $join->conditions[0]['operator']);
    }

    public function testJoinClauseTrimsIdentifiers(): void
    {
        $join = $this->makeJoin();
        $join->on('  users.id  ', '=', '  orders.user_id  ');
        $this->assertSame('users.id', $join->conditions[0]['first']);
        $this->assertSame('orders.user_id', $join->conditions[0]['second']);
    }

    public function testJoinClauseRejectsUnsupportedOperator(): void
    {
        $join = $this->makeJoin();
        $this->expectException(RuntimeException::class);
        $join->on('a', '^^^', 'b');
    }

    public function testJoinClauseCompileSingleOn(): void
    {
        $join = $this->makeJoin();
        $join->on('users.id', '=', 'orders.user_id');
        $compiled = $join->compile();
        $this->assertSame('`users`.`id` = `orders`.`user_id`', $compiled);
    }

    public function testJoinClauseCompileMultipleWithBoolean(): void
    {
        $join = $this->makeJoin();
        $join->on('users.id', '=', 'orders.user_id');
        $join->where('orders.status', '=', 'active');
        $compiled = $join->compile();
        $this->assertSame('`users`.`id` = `orders`.`user_id` AND ? = ?', $compiled);
    }

    public function testJoinClauseCompileOrOn(): void
    {
        $join = $this->makeJoin();
        $join->on('a', '=', 'b');
        $join->orOn('c', '=', 'd');
        $compiled = $join->compile();
        $this->assertStringContainsString(' OR ', $compiled);
    }

    public function testJoinClauseTypeAndTable(): void
    {
        $join = new JoinClause('right', 'items', new SqlCompiler());
        $this->assertSame('right', $join->type);
        $this->assertSame('items', $join->table);
    }

    public function testForeignKeyChainable(): void
    {
        $fk = new ForeignKey('user_id');
        $result = $fk->references('id')->on('users')->onDelete('CASCADE')->onUpdate('SET NULL');
        $this->assertSame($fk, $result);
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('id', $fk->references);
        $this->assertSame('users', $fk->onTable);
        $this->assertSame('CASCADE', $fk->onDelete);
        $this->assertSame('SET NULL', $fk->onUpdate);
    }

    public function testForeignKeyUpperCasesActions(): void
    {
        $fk = new ForeignKey('col');
        $fk->onDelete('cascade')->onUpdate('set null');
        $this->assertSame('CASCADE', $fk->onDelete);
        $this->assertSame('SET NULL', $fk->onUpdate);
    }

    public function testForeignKeyDefaultsEmpty(): void
    {
        $fk = new ForeignKey('col');
        $this->assertSame('', $fk->references);
        $this->assertSame('', $fk->onTable);
        $this->assertSame('', $fk->onDelete);
        $this->assertSame('', $fk->onUpdate);
    }

    public function testDatabaseConnectionExceptionMessage(): void
    {
        $e = new DatabaseConnectionException('mysql', '127.0.0.1', 3306, 'Connection refused');
        $this->assertStringContainsString('mysql database at 127.0.0.1:3306', $e->getMessage());
        $this->assertStringContainsString('Connection refused', $e->getMessage());
        $this->assertStringContainsString('DB_HOST', $e->getMessage());
    }

    public function testDatabaseConnectionExceptionAccessors(): void
    {
        $e = new DatabaseConnectionException('pgsql', 'db.example.com', 5432, 'timeout');
        $this->assertSame('pgsql', $e->getDriver());
        $this->assertSame('db.example.com', $e->getDbHost());
        $this->assertSame(0, $e->getCode());
        $this->assertSame(RuntimeException::class, get_parent_class($e));
    }

    public function testDatabaseConnectionExceptionIsRuntime(): void
    {
        $e = new DatabaseConnectionException('sqlite', 'localhost', 0, 'disk I/O error');
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertStringContainsString('sqlite', $e->getMessage());
    }
}
