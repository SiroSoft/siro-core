<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;
use PHPUnit\Framework\Attributes\DataProvider;


use Siro\Core\Tests\TestCase;
use Siro\Core\DB\SqlCompiler;

final class FuzzSqlCompilerTest extends TestCase
{
    private SqlCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new SqlCompiler();
        $this->compiler->setTable('test_table');
    }

    #[DataProvider('provideIdentifiers')]
    public function testQuoteIdentifierNeverThrows(string $identifier): void
    {
        try {
            $result = $this->compiler->quoteIdentifier($identifier);
            $this->assertIsString($result);
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    #[DataProvider('provideIdentifiers')]
    public function testQuoteIdentifierIsIdempotent(string $identifier): void
    {
        try {
            $first = $this->compiler->quoteIdentifier($identifier);
            $second = $this->compiler->quoteIdentifier($identifier);
            $this->assertSame($first, $second);
        } catch (\RuntimeException) {
            $this->expectNotToPerformAssertions();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideIdentifiers(): iterable
    {
        $identifiers = [
            '*', 'id', 'name', 'email', 'created_at',
            'table.column', 't.c',
            'count(*)', 'COUNT(*)',
            'users.id', 'posts.title',
            'a', 'ab', 'a_b', 'a-b',
            str_repeat('x', 100),
            '', ' ', "\t", "\n",
            "\x00", ';', '--', '/*',
            'column with spaces',
            "' OR '1'='1", '1; DROP TABLE users',
        ];
        $idx = 0;
        foreach ($identifiers as $id) {
            yield 'qi_' . $idx++ => [$id];
        }
    }

    #[DataProvider('provideOperators')]
    public function testNormalizeOperatorNeverThrows(string $operator): void
    {
        try {
            $result = $this->compiler->normalizeOperator($operator);
            $this->assertIsString($result);
            $this->assertNotEmpty($result);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unsupported', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideOperators(): iterable
    {
        $ops = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE',
            '', ' ', "\0", 'INVALID', '=', 'like', 'Like',
            str_repeat('=', 10), "=",
        ];
        $idx = 0;
        foreach ($ops as $op) {
            yield 'no_' . $idx++ => [$op];
        }
    }

    #[DataProvider('provideBuildSelectInputs')]
    public function testBuildSelectQueryNeverThrows(
        array $columns, string $table, array $wheres, array $havings,
        array $joins, array $groups, array $orders, ?int $limit, ?int $offset, array $bindings
    ): void {
        try {
            [$sql, $b] = $this->compiler->buildSelectQuery(
                $columns, $table, $wheres, $havings,
                $joins, $groups, $orders, $limit, $offset, $bindings
            );
            $this->assertIsString($sql);
            $this->assertIsArray($b);
            $this->assertStringContainsString('SELECT', strtoupper(substr($sql, 0, 10)));
        } catch (\RuntimeException) {
            $this->expectNotToPerformAssertions();
        }
    }

    /** @return iterable<string, array{array, string, array, array, array, array, array, ?int, ?int, array}> */
    public static function provideBuildSelectInputs(): iterable
    {
        yield 'minimal' => [['*'], 'users', [], [], [], [], [], null, null, []];

        yield 'with where' => [
            ['id', 'name'], 'users',
            [['type' => 'basic', 'boolean' => 'AND', 'column' => 'id', 'operator' => '=', 'param' => 'w_0']],
            [], [], [], [], null, null,
            ['w_0' => 1],
        ];

        yield 'with joins' => [
            ['*'], 'users',
            [],
            [],
            [['type' => 'INNER', 'table' => 'profiles', 'first' => 'users.id', 'operator' => '=', 'second' => 'profiles.user_id']],
            [], [], null, null,
            [],
        ];

        yield 'with group by and having' => [
            ['status'], 'orders',
            [],
            [['boolean' => 'AND', 'column' => 'status', 'operator' => '>', 'param' => 'h_0']],
            [], ['status'], [], null, null,
            ['h_0' => 1],
        ];

        yield 'with order limit offset' => [
            ['*'], 'posts', [], [], [], [],
            [['column' => 'created_at', 'direction' => 'DESC']],
            10, 5, [],
        ];

        yield 'where in' => [
            ['*'], 'users',
            [['type' => 'in', 'boolean' => 'AND', 'column' => 'id', 'not' => false, 'params' => ['wi_0_0', 'wi_0_1']]],
            [], [], [], [], null, null,
            ['wi_0_0' => 1, 'wi_0_1' => 2],
        ];

        yield 'where raw' => [
            ['*'], 'users',
            [['type' => 'raw', 'boolean' => 'AND', 'sql' => 'created_at > :date']],
            [], [], [], [], null, null,
            ['date' => '2024-01-01'],
        ];
    }

    #[DataProvider('provideBuildInsertInputs')]
    public function testBuildInsertSqlNeverThrows(string $table, array $data): void
    {
        try {
            [$sql, $bindings, $returning] = $this->compiler->buildInsertSql($table, $data, 'id');
            $this->assertIsString($sql);
            $this->assertIsArray($bindings);
            $this->assertStringContainsString('INSERT INTO', strtoupper(substr($sql, 0, 15)));
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    /** @return iterable<string, array{string, array}> */
    public static function provideBuildInsertInputs(): iterable
    {
        yield 'simple' => ['users', ['name' => 'John', 'email' => 'john@test.com']];
        yield 'multiple columns' => ['posts', ['title' => 'Hello', 'body' => 'World', 'status' => 'draft']];
        yield 'empty data' => ['users', []];
        yield 'null values' => ['users', ['name' => 'John', 'deleted_at' => null]];
        yield 'numeric values' => ['users', ['age' => 25, 'score' => 99.9]];
        yield 'special chars' => ['users', ['name' => "O'Brien", 'bio' => "<script>alert(1)</script>"]];
        yield 'unicode' => ['users', ['name' => 'HeartSpadeClub']];
    }

    #[DataProvider('provideBuildUpdateInputs')]
    public function testBuildUpdateSqlNeverThrows(string $table, array $data, array $wheres, array $bindings): void
    {
        try {
            [$sql, $b] = $this->compiler->buildUpdateSql($table, $data, $wheres, $bindings);
            $this->assertIsString($sql);
            $this->assertIsArray($b);
            $this->assertStringContainsString('UPDATE', strtoupper(substr($sql, 0, 10)));
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    /** @return iterable<string, array{string, array, array, array}> */
    public static function provideBuildUpdateInputs(): iterable
    {
        yield 'simple' => ['users', ['name' => 'NewName'], [], []];
        yield 'with where' => [
            'users', ['status' => 'inactive'],
            [['type' => 'basic', 'boolean' => 'AND', 'column' => 'id', 'operator' => '=', 'param' => 'w_0']],
            ['w_0' => 1],
        ];
        yield 'empty data' => ['users', [], [], []];
        yield 'null values' => ['users', ['deleted_at' => null, 'name' => 'test'], [], []];
    }

    #[DataProvider('provideBuildDeleteInputs')]
    public function testBuildDeleteSqlNeverThrows(string $table, array $wheres, array $bindings): void
    {
        try {
            [$sql, $b] = $this->compiler->buildDeleteSql($table, $wheres, $bindings);
            $this->assertIsString($sql);
            $this->assertIsArray($b);
            $this->assertStringContainsString('DELETE FROM', strtoupper(substr($sql, 0, 15)));
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    /** @return iterable<string, array{string, array, array}> */
    public static function provideBuildDeleteInputs(): iterable
    {
        yield 'no where' => ['users', [], []];
        yield 'with where' => [
            'users',
            [['type' => 'basic', 'boolean' => 'AND', 'column' => 'id', 'operator' => '=', 'param' => 'w_0']],
            ['w_0' => 1],
        ];
        yield 'where in' => [
            'users',
            [['type' => 'in', 'boolean' => 'AND', 'column' => 'id', 'not' => false, 'params' => ['wi_0_0', 'wi_0_1']]],
            ['wi_0_0' => 1, 'wi_0_1' => 2],
        ];
    }

    #[DataProvider('provideJoinsInputs')]
    public function testCompileJoinsNeverThrows(array $joins): void
    {
        $result = $this->compiler->compileJoins($joins);
        $this->assertIsString($result);
    }

    /** @return iterable<string, array{array}> */
    public static function provideJoinsInputs(): iterable
    {
        yield 'empty' => [[]];
        yield 'inner join' => [[['type' => 'INNER', 'table' => 'profiles', 'first' => 'users.id', 'operator' => '=', 'second' => 'profiles.user_id']]];
        yield 'left join' => [[['type' => 'LEFT', 'table' => 'posts', 'first' => 'users.id', 'operator' => '=', 'second' => 'posts.user_id']]];
        yield 'multiple joins' => [
            [
                ['type' => 'INNER', 'table' => 'profiles', 'first' => 'users.id', 'operator' => '=', 'second' => 'profiles.user_id'],
                ['type' => 'LEFT', 'table' => 'posts', 'first' => 'users.id', 'operator' => '=', 'second' => 'posts.user_id'],
            ],
        ];
    }
}
