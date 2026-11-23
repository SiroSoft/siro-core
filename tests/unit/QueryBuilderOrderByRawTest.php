<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\DB\QueryBuilder;

/**
 * orderByRaw() must not append a direction the expression already
 * carries. Regression test for the MySQL 1064 caused by
 * "ORDER BY ... ASC ASC" (SiroERP StockOverviewTrait sort bug).
 */
final class QueryBuilderOrderByRawTest extends TestCase
{
    public function testDefaultDirection(): void
    {
        $sql = (new QueryBuilder('users'))->orderByRaw('quantity_on_hand')->toSql();
        $this->assertStringContainsString('ORDER BY quantity_on_hand ASC', $sql);
    }

    public function testExplicitDesc(): void
    {
        $sql = (new QueryBuilder('users'))->orderByRaw('quantity_on_hand', 'desc')->toSql();
        $this->assertStringContainsString('ORDER BY quantity_on_hand DESC', $sql);
    }

    public function testExpressionWithAsc(): void
    {
        $sql = (new QueryBuilder('users'))->orderByRaw('COALESCE(expiry_date, received_at) ASC')->toSql();
        $this->assertStringContainsString('ORDER BY COALESCE(expiry_date, received_at) ASC', $sql);
        $this->assertStringNotContainsString('ASC ASC', $sql);
    }

    public function testExpressionWithDesc(): void
    {
        $sql = (new QueryBuilder('users'))->orderByRaw('quantity_on_hand DESC')->toSql();
        $this->assertStringContainsString('ORDER BY quantity_on_hand DESC', $sql);
        $this->assertStringNotContainsString('DESC ASC', $sql);
    }

    public function testExpressionWithLowercaseDesc(): void
    {
        $sql = (new QueryBuilder('users'))->orderByRaw('quantity_on_hand desc')->toSql();
        $this->assertStringContainsString('ORDER BY quantity_on_hand desc', $sql);
        $this->assertStringNotContainsString('DESC ASC', $sql);
        $this->assertStringNotContainsString('desc ASC', $sql);
    }

    public function testNullDirectionMeansExpressionCarriesIt(): void
    {
        $sql = (new QueryBuilder('users'))->orderByRaw('RAND()', null)->toSql();
        $this->assertStringContainsString('ORDER BY RAND()', $sql);
        $this->assertStringNotContainsString('RAND() ASC', $sql);
    }

    public function testMixedRawAndColumnOrders(): void
    {
        $sql = (new QueryBuilder('users'))
            ->orderByRaw('COALESCE(a, b) DESC')
            ->orderBy('name')
            ->toSql();
        $this->assertStringContainsString('ORDER BY COALESCE(a, b) DESC, `name` ASC', $sql);
    }
}
