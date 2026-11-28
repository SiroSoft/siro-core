<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use InvalidArgumentException;
use Siro\Core\DB\ApiQueryFilter;
use Siro\Core\DB\Relations\BelongsTo;
use Siro\Core\Model;
use Siro\Core\Request;
use Siro\Core\Tests\TestCase;

/** @covers \Siro\Core\DB\ApiQueryFilter */
final class ApiQueryFilterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config = [
        'filterable' => ['status', 'category_id', 'price', 'name'],
        'sortable' => ['created_at', 'price', 'name'],
        'includable' => ['category'],
        'fields' => ['id', 'name', 'price', 'status', 'created_at'],
        'per_page' => 15,
    ];

    private function request(array $query): Request
    {
        return new Request('GET', '/products', $query);
    }

    public function testParseEmptyQueryReturnsDefaults(): void
    {
        $parsed = ApiQueryFilter::parse($this->request([]), ApiFilterProduct::class, $this->config);

        $this->assertSame([], $parsed['filters']);
        $this->assertSame([], $parsed['sorts']);
        $this->assertSame([], $parsed['includes']);
        $this->assertNull($parsed['fields']);
        $this->assertSame(1, $parsed['page']);
        $this->assertSame(15, $parsed['per_page']);
    }

    public function testParseSimpleFilterIsEq(): void
    {
        $parsed = ApiQueryFilter::parse(
            $this->request(['filter' => ['status' => 'active']]),
            ApiFilterProduct::class,
            $this->config
        );

        $this->assertSame([['field' => 'status', 'op' => 'eq', 'value' => 'active']], $parsed['filters']);
    }

    public function testParseAllOperators(): void
    {
        $parsed = ApiQueryFilter::parse(
            $this->request(['filter' => [
                'price' => ['gte' => '10', 'lte' => '99'],
                'name' => ['like' => 'shoe'],
                'status' => ['ne' => 'draft'],
                'category_id' => ['in' => '1,2,3'],
            ]]),
            ApiFilterProduct::class,
            $this->config
        );

        $this->assertSame([
            ['field' => 'price', 'op' => 'gte', 'value' => '10'],
            ['field' => 'price', 'op' => 'lte', 'value' => '99'],
            ['field' => 'name', 'op' => 'like', 'value' => '%shoe%'],
            ['field' => 'status', 'op' => 'ne', 'value' => 'draft'],
            ['field' => 'category_id', 'op' => 'in', 'value' => ['1', '2', '3']],
        ], $parsed['filters']);
    }

    public function testParseInAcceptsArrayValues(): void
    {
        $parsed = ApiQueryFilter::parse(
            $this->request(['filter' => ['status' => ['nin' => ['a', 'b']]]]),
            ApiFilterProduct::class,
            $this->config
        );

        $this->assertSame([['field' => 'status', 'op' => 'nin', 'value' => ['a', 'b']]], $parsed['filters']);
    }

    public function testParseSortMultiFieldWithDirection(): void
    {
        $parsed = ApiQueryFilter::parse(
            $this->request(['sort' => '-created_at,price']),
            ApiFilterProduct::class,
            $this->config
        );

        $this->assertSame([
            ['field' => 'created_at', 'direction' => 'desc'],
            ['field' => 'price', 'direction' => 'asc'],
        ], $parsed['sorts']);
    }

    public function testParseIncludeAndFields(): void
    {
        $parsed = ApiQueryFilter::parse(
            $this->request(['include' => 'category', 'fields' => 'id,name']),
            ApiFilterProduct::class,
            $this->config
        );

        $this->assertSame(['category'], $parsed['includes']);
        $this->assertSame(['id', 'name'], $parsed['fields']);
    }

    public function testParsePagination(): void
    {
        $parsed = ApiQueryFilter::parse(
            $this->request(['page' => '3', 'per_page' => '20']),
            ApiFilterProduct::class,
            $this->config
        );

        $this->assertSame(3, $parsed['page']);
        $this->assertSame(20, $parsed['per_page']);
    }

    public function testFilterUnknownFieldThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Field 'password' is not allowed in query parameter 'filter'.");

        ApiQueryFilter::parse(
            $this->request(['filter' => ['password' => 'x']]),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testFilterUnknownOperatorThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid filter operator 'regexp'");

        ApiQueryFilter::parse(
            $this->request(['filter' => ['status' => ['regexp' => 'x']]]),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testFilterNonArrayThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['filter' => 'status']),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testFilterArrayValueForScalarOpThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['filter' => ['status' => ['eq' => ['a', 'b']]]]),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testFilterEmptyInThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['filter' => ['status' => ['in' => '']]]),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testSortUnknownFieldThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Field 'password' is not allowed in query parameter 'sort'.");

        ApiQueryFilter::parse(
            $this->request(['sort' => 'password']),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testSortBySensitiveColumnThrows(): void
    {
        // 'id' is NOT in the sortable whitelist: sorting by PK is not allowed
        // unless the app whitelists it explicitly.
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['sort' => 'id']),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testIncludeUnknownRelationThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['include' => 'owner']),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testIncludeNestedThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['include' => 'category.parent']),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testIncludeWhitelistedButMissingMethodThrows(): void
    {
        $config = $this->config;
        $config['includable'] = ['ghost'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist on model');

        ApiQueryFilter::parse(
            $this->request(['include' => 'ghost']),
            ApiFilterProduct::class,
            $config
        );
    }

    public function testFieldsUnknownFieldThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['fields' => 'id,password']),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testMissingWhitelistMeansParamRejected(): void
    {
        // No 'sortable' option at all: any ?sort= is a 400-equivalent.
        $config = $this->config;
        unset($config['sortable']);

        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['sort' => 'name']),
            ApiFilterProduct::class,
            $config
        );
    }

    public function testInvalidIdentifierThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['filter' => ['status; DROP TABLE x' => '1']]),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testInvalidPageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['page' => 'abc']),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testPerPageOverLimitThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse(
            $this->request(['per_page' => '101']),
            ApiFilterProduct::class,
            $this->config
        );
    }

    public function testInvalidConfigWhitelistThrows(): void
    {
        $config = $this->config;
        $config['filterable'] = ['status', 'bad-name!'];

        $this->expectException(InvalidArgumentException::class);

        ApiQueryFilter::parse($this->request([]), ApiFilterProduct::class, $config);
    }
}
