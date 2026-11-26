<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\DB\QueryBuilder;

/**
 * QueryBuilder Unit Tests
 * 
 * Tests database query builder functionality
 */
final class QueryBuilderTest extends TestCase
{
    /**
     * Test QueryBuilder has select method
     */
    public function testQueryBuilderHasSelectMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'select'));
    }

    /**
     * Test QueryBuilder has where method
     */
    public function testQueryBuilderHasWhereMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'where'));
    }

    /**
     * Test QueryBuilder has orderBy method
     */
    public function testQueryBuilderHasOrderByMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'orderBy'));
    }

    /**
     * Test QueryBuilder has insert method
     */
    public function testQueryBuilderHasInsertMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'insert'));
    }

    /**
     * Test QueryBuilder has update method
     */
    public function testQueryBuilderHasUpdateMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'update'));
    }

    /**
     * Test QueryBuilder has delete method
     */
    public function testQueryBuilderHasDeleteMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'delete'));
    }

    /**
     * Test QueryBuilder has get method
     */
    public function testQueryBuilderHasGetMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'get'));
    }

    /**
     * Test QueryBuilder has first method
     */
    public function testQueryBuilderHasFirstMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'first'));
    }

    /**
     * Test QueryBuilder has paginate method
     */
    public function testQueryBuilderHasPaginateMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'paginate'));
    }

    /**
     * Test QueryBuilder has limit method
     */
    public function testQueryBuilderHasLimitMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'limit'));
    }

    /**
     * Test QueryBuilder has offset method
     */
    public function testQueryBuilderHasOffsetMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'offset'));
    }

    /**
     * Test QueryBuilder has groupBy method
     */
    public function testQueryBuilderHasGroupByMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'groupBy'));
    }

    /**
     * Test QueryBuilder has having method
     */
    public function testQueryBuilderHasHavingMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'having'));
    }

    /**
     * Test QueryBuilder has join method
     */
    public function testQueryBuilderHasJoinMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'join'));
    }

    /**
     * Test QueryBuilder has leftJoin method
     */
    public function testQueryBuilderHasLeftJoinMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'leftJoin'));
    }

    /**
     * Test QueryBuilder has cache method
     */
    public function testQueryBuilderHasCacheMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'cache'));
    }

    /**
     * Test QueryBuilder has count method
     */
    public function testQueryBuilderHasCountMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'count'));
    }

    /**
     * Test QueryBuilder has sum method
     */
    public function testQueryBuilderHasSumMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'sum'));
    }

    /**
     * Test QueryBuilder has avg method
     */
    public function testQueryBuilderHasAvgMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'avg'));
    }

    /**
     * Test QueryBuilder has min method
     */
    public function testQueryBuilderHasMinMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'min'));
    }

    /**
     * Test QueryBuilder has max method
     */
    public function testQueryBuilderHasMaxMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'max'));
    }

    /**
     * Test QueryBuilder has exists method
     */
    public function testQueryBuilderHasExistsMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'exists'));
    }

    /**
     * Test QueryBuilder has toSql method
     */
    public function testQueryBuilderHasToSqlMethod(): void
    {
        $this->assertTrue(method_exists(QueryBuilder::class, 'toSql'));
    }

    /**
     * F1 (v1.4.1): a bare where('col') used to silently become
     * `col IS NULL`. It must now throw so typos can't hide.
     */
    public function testBareWhereThrowsInsteadOfSilentlyBecomingIsNull(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a value/');
        (new QueryBuilder('users'))->where('deleted_at');
    }

    public function testBareOrWhereThrowsInsteadOfSilentlyBecomingIsNull(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires a value/');
        (new QueryBuilder('users'))->where('id', 1)->orWhere('deleted_at');
    }

    /**
     * Explicit null still means IS NULL — only the silent 1-arg form throws.
     */
    public function testWhereWithExplicitNullStillMeansIsNull(): void
    {
        $sql = (new QueryBuilder('users'))->where('deleted_at', null)->toSql();
        $this->assertStringContainsString('IS NULL', $sql);
    }
}
