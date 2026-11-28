<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\DB\QueryBuilder;
use Siro\Core\DB\SoftDeletes;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * ModelQueryBuilder::toBase() returns a plain QueryBuilder carrying
 * the same conditions, so multi-table reports do not hydrate
 * partial rows into models. fromSub() on the model builder keeps
 * hydrating (documented); reports must call toBase() first.
 */
final class ModelQueryBuilderToBaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE mqbt_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, qty INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        Database::execute('CREATE TABLE mqbt_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
        Database::table('mqbt_items')->insert(['name' => 'A', 'qty' => 10]);
        Database::table('mqbt_items')->insert(['name' => 'B', 'qty' => 20]);
        Database::table('mqbt_categories')->insert(['label' => 'x']);
        MQBTItem::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testToBaseReturnsPlainBuilder(): void
    {
        $base = MQBTItem::query()->where('qty', '>', 5)->toBase();
        $this->assertInstanceOf(QueryBuilder::class, $base);
        $this->assertNotInstanceOf(\Siro\Core\DB\ModelQueryBuilder::class, $base);
    }

    public function testToBaseCarriesConditions(): void
    {
        [$sql, $bindings] = MQBTItem::query()->where('qty', '>', 5)->toBase()->toCompiled();
        $this->assertStringContainsString('FROM `mqbt_items`', $sql);
        $this->assertStringContainsString('WHERE `qty` > :w_0', $sql);
        $this->assertSame(['w_0' => 5], $bindings);
    }

    public function testToBaseResultsArePlainArrays(): void
    {
        $rows = MQBTItem::query()->where('qty', '>', 5)->toBase()->get();
        $this->assertCount(2, $rows);
        $this->assertIsArray($rows[0]);
        $this->assertSame('A', $rows[0]['name']);
    }

    public function testToBaseWithJoinAndFromSub(): void
    {
        $rows = MQBTItem::query()
            ->where('qty', '>', 5)
            ->toBase()
            ->join('mqbt_categories', 'mqbt_items.id', '=', 'mqbt_categories.id')
            ->select('mqbt_items.name', 'mqbt_categories.label')
            ->get();
        $this->assertCount(1, $rows);
        $this->assertIsArray($rows[0]);
        $this->assertSame('x', $rows[0]['label']);
    }

    public function testFromSubOnModelBuilderStillHydrates(): void
    {
        // Documented behaviour: fromSub() on the model builder returns
        // models; use toBase()->fromSub(...) for plain arrays.
        $rows = MQBTItem::query()
            ->fromSub(
                (new QueryBuilder('mqbt_items'))->where('qty', '>', 15)->select('*'),
                'sub'
            )
            ->get();
        $this->assertCount(1, $rows);
        $this->assertInstanceOf(MQBTItem::class, $rows[0]);
        $this->assertSame('B', $rows[0]->name);
    }
}
