<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;

use Siro\Core\Tests\TestCase;
use Siro\Core\DB\QueryBuilder;

final class FuzzQueryBuilderTest extends TestCase
{
    /** @dataProvider provideTableNames */
    public function testConstructorNeverThrows(string $table): void
    {
        try {
            $qb = new QueryBuilder($table);
            $this->assertInstanceOf(QueryBuilder::class, $qb);
        } catch (\Throwable $e) {
            $trimmed = trim($table);
            $this->assertTrue($trimmed === '', 'Only empty/whitespace table name should throw, got: ' . $table);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideTableNames(): iterable
    {
        $tables = [
            'users', 'posts', 'comments', 'users_posts',
            'User', 'USER', 'users_123', '123users',
            'a', 'ab',
            str_repeat('x', 255),
            '', ' ', "\t",
            '`users`', '"users"',
        ];
        $idx = 0;
        foreach ($tables as $table) {
            yield 'tn_' . $idx++ => [$table];
        }
    }

    /** @dataProvider provideChainedMethods */
    public function testChainedMethodsNeverThrow(QueryBuilder $qb): void
    {
        $result = $qb->toSql();
        $this->assertIsString($result);
    }

    /** @return iterable<string, array{QueryBuilder}> */
    public static function provideChainedMethods(): iterable
    {
        $base = new QueryBuilder('users');

        yield 'empty' => [clone $base];

        $b = clone $base;
        $b->select(['*']);
        yield 'select star' => [$b];

        $b = clone $base;
        $b->select(['id', 'name', 'email']);
        yield 'select columns' => [$b];

        $b = clone $base;
        $b->where('id', '=', 1);
        yield 'where basic' => [$b];

        $b = clone $base;
        $b->where('name', 'John');
        yield 'where shorthand' => [$b];

        $b = clone $base;
        $b->where('age', '>', 18)->orWhere('age', '<', 5);
        yield 'where orWhere' => [$b];

        $b = clone $base;
        $b->whereIn('id', [1, 2, 3]);
        yield 'whereIn' => [$b];

        $b = clone $base;
        $b->whereNull('deleted_at');
        yield 'whereNull' => [$b];

        $b = clone $base;
        $b->whereNotNull('email');
        yield 'whereNotNull' => [$b];

        $b = clone $base;
        $b->orderBy('created_at', 'desc');
        yield 'orderBy desc' => [$b];

        $b = clone $base;
        $b->limit(10)->offset(5);
        yield 'limit offset' => [$b];

        $b = clone $base;
        $b->groupBy('status')->having('count', '>', 1);
        yield 'groupBy having' => [$b];

        $b = clone $base;
        $b->select(['id'])->where('active', true)->orderBy('name')->limit(100);
        yield 'full chain' => [$b];

        $b = clone $base;
        $b->whereBetween('age', [18, 65]);
        yield 'whereBetween' => [$b];

        $b = clone $base;
        $b->whereNotBetween('age', [0, 17]);
        yield 'whereNotBetween' => [$b];

        $b = clone $base;
        $b->join('profiles', 'users.id', '=', 'profiles.user_id');
        yield 'join' => [$b];

        $b = clone $base;
        $b->leftJoin('profiles', 'users.id', '=', 'profiles.user_id');
        yield 'leftJoin' => [$b];

        $b = clone $base;
        $b->whereRaw('created_at > :date', ['date' => '2024-01-01']);
        yield 'whereRaw' => [$b];
    }

    /** @dataProvider provideWhereValueFuzz */
    public function testWhereWithFuzzValuesNeverThrows(string $column, mixed $operator, mixed $value): void
    {
        $qb = new QueryBuilder('test_table');
        try {
            $qb->where($column, $operator, $value);
            $sql = $qb->toSql();
            $this->assertIsString($sql);
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('Unsupported', $e->getMessage());
        }
    }

    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function provideWhereValueFuzz(): iterable
    {
        $columns = ['id', 'name', 'email', 'price', 'status', ''];
        $operators = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'INVALID', ''];
        $values = [
            null, true, false, 0, 1, -1, 3.14, '', 'test',
            "%", "%test%", "' OR '1'='1", "\0", "\n",
            [], ['x'],
        ];
        $idx = 0;
        foreach ($columns as $col) {
            foreach ($operators as $op) {
                foreach ($values as $val) {
                    yield 'wv_' . $idx++ => [$col, $op, $val];
                }
            }
        }
    }

    /** @dataProvider provideOrderByFuzz */
    public function testOrderByFuzzNeverThrows(string $column, string $direction): void
    {
        $qb = new QueryBuilder('test');
        try {
            $qb->orderBy($column, $direction);
            $sql = $qb->toSql();
            $this->assertIsString($sql);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid identifier', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideOrderByFuzz(): iterable
    {
        $columns = ['id', 'name', '', "\0", 'column with spaces'];
        $dirs = ['asc', 'desc', 'ASC', 'DESC', '', 'invalid', 'RAND()'];
        $idx = 0;
        foreach ($columns as $col) {
            foreach ($dirs as $dir) {
                yield 'ob_' . $idx++ => [$col, $dir];
            }
        }
    }

    /** @dataProvider provideSelectFuzz */
    public function testSelectFuzzNeverThrows(array $columns): void
    {
        $qb = new QueryBuilder('test');
        try {
            $qb->select($columns);
            $sql = $qb->toSql();
            $this->assertIsString($sql);
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Invalid identifier', $e->getMessage());
        }
    }

    /** @return iterable<string, array{array}> */
    public static function provideSelectFuzz(): iterable
    {
        yield 'star' => [['*']];
        yield 'multiple' => [['id', 'name', 'email']];
        yield 'empty' => [[]];
        yield 'with aliases' => [['id AS user_id', 'name AS user_name']];
        yield 'function' => [['COUNT(*)']];
        yield 'raw expression' => [['DISTINCT category']];
    }
}
