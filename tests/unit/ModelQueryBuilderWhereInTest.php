<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\DB\SoftDeletes;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * ModelQueryBuilder must expose whereIn()/orWhereIn()/whereNotIn()
 * as real methods (IDE/PHPStan discoverable) and as static calls
 * via __callStatic.
 */
final class ModelQueryBuilderWhereInTest extends TestCase
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
        Database::execute('CREATE TABLE mqbwi_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, qty INTEGER, price REAL,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        Database::table('mqbwi_items')->insert(['name' => 'A', 'qty' => 10, 'price' => 5.5]);
        Database::table('mqbwi_items')->insert(['name' => 'B', 'qty' => 20, 'price' => 2.5]);
        Database::table('mqbwi_items')->insert(['name' => 'C', 'qty' => 30, 'price' => 1.5]);
        MQBWIItem::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testMethodsExist(): void
    {
        $this->assertTrue(method_exists(MQBWIItem::query(), 'whereIn'));
        $this->assertTrue(method_exists(MQBWIItem::query(), 'orWhereIn'));
        $this->assertTrue(method_exists(MQBWIItem::query(), 'whereNotIn'));
    }

    public function testWhereInToSql(): void
    {
        [$sql, $bindings] = MQBWIItem::query()->whereIn('id', [1, 2])->toCompiled();
        $this->assertStringContainsString('`id` IN (:wi_0_0, :wi_0_1)', $sql);
        $this->assertSame(['wi_0_0' => 1, 'wi_0_1' => 2], $bindings);
    }

    public function testWhereInReturnsModels(): void
    {
        $rows = MQBWIItem::query()->whereIn('id', [1, 3])->get();
        $this->assertCount(2, $rows);
        $this->assertContainsOnlyInstancesOf(MQBWIItem::class, $rows);
    }

    public function testOrWhereInReturnsModels(): void
    {
        $rows = MQBWIItem::query()->where('id', 1)->orWhereIn('id', [3])->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereNotInReturnsModels(): void
    {
        $rows = MQBWIItem::query()->whereNotIn('id', [1, 2])->get();
        $this->assertCount(1, $rows);
        $this->assertSame('C', $rows[0]->name);
    }

    public function testStaticCallViaMagic(): void
    {
        $rows = MQBWIItem::whereIn('id', [2])->get();
        $this->assertCount(1, $rows);
        $this->assertSame('B', $rows[0]->name);
    }

    public function testWhereInInsideGroup(): void
    {
        $rows = MQBWIItem::query()
            ->where(fn ($q) => $q->whereIn('id', [1, 2])->orWhere('name', 'C'))
            ->get();
        $this->assertCount(3, $rows);
    }
}
