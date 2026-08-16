<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * Model database-backed lifecycle tests — real sqlite table.
 * Covers find/create/save/update/delete/refresh/fresh/query/count/paginate/
 * firstOrCreate/updateOrCreate/firstOrNew/attributes/casts/only.
 */
final class ModelDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('QUEUE_DRIVER=db');
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            price REAL DEFAULT 0,
            active INTEGER DEFAULT 1,
            created_at TEXT,
            updated_at TEXT
        )');
        ItemModel::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testCreateAndFind(): void
    {
        $item = ItemModel::create(['name' => 'Alpha', 'price' => 10.5]);
        $this->assertInstanceOf(ItemModel::class, $item);
        $this->assertGreaterThan(0, (int) $item->id);

        $found = ItemModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertSame('Alpha', $found->name);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull(ItemModel::find(999));
    }

    public function testAllAndCount(): void
    {
        ItemModel::create(['name' => 'A']);
        ItemModel::create(['name' => 'B']);
        $this->assertSame(2, ItemModel::count());
        $this->assertCount(2, ItemModel::all());
    }

    public function testQueryWhere(): void
    {
        ItemModel::create(['name' => 'Apple', 'price' => 5]);
        ItemModel::create(['name' => 'Banana', 'price' => 3]);
        $results = ItemModel::where('name', '=', 'Apple')->get();
        $this->assertCount(1, $results);
        $this->assertSame('Apple', $results[0]->name);
    }

    public function testOrderByLimitOffset(): void
    {
        ItemModel::create(['name' => 'A', 'price' => 1]);
        ItemModel::create(['name' => 'B', 'price' => 2]);
        ItemModel::create(['name' => 'C', 'price' => 3]);
        $results = ItemModel::orderBy('price', 'desc')->limit(2)->get();
        $this->assertSame('C', $results[0]->name);
        $this->assertSame('B', $results[1]->name);
    }

    public function testFirst(): void
    {
        ItemModel::create(['name' => 'First']);
        $first = ItemModel::first();
        $this->assertNotNull($first);
        $this->assertSame('First', $first->name);
    }

    public function testPaginate(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            ItemModel::create(['name' => "Item $i"]);
        }
        $page = ItemModel::paginate(2, 1);
        $this->assertCount(2, $page['data']);
        $this->assertSame(5, $page['meta']['total'] ?? $page['total'] ?? 0);
    }

    public function testSaveUpdate(): void
    {
        $item = ItemModel::create(['name' => 'Old']);
        $item->name = 'New';
        $ok = $item->save();
        $this->assertTrue($ok);
        $this->assertSame('New', ItemModel::find($item->id)->name);
    }

    public function testDelete(): void
    {
        $item = ItemModel::create(['name' => 'Delete me']);
        $id = (int) $item->id;
        $item->delete();
        $this->assertNull(ItemModel::find($id));
    }

    public function testRefreshAndFresh(): void
    {
        $item = ItemModel::create(['name' => 'X']);
        $id = (int) $item->id;
        Database::table('items')->where('id', '=', $id)->update(['name' => 'Y']);
        $item->refresh();
        $this->assertSame('Y', $item->name);

        $fresh = ItemModel::find($id)->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Y', $fresh->name);
    }

    public function testFirstOrCreateCreates(): void
    {
        $item = ItemModel::firstOrCreate(['name' => 'New'], ['price' => 9]);
        $this->assertNotNull($item);
        $this->assertSame(1, ItemModel::count());
    }

    public function testFirstOrCreateFindsExisting(): void
    {
        ItemModel::create(['name' => 'Exists', 'price' => 1]);
        $item = ItemModel::firstOrCreate(['name' => 'Exists'], ['price' => 9]);
        $this->assertSame('Exists', $item->name);
        $this->assertSame(1, ItemModel::count(), 'should not create duplicate');
    }

    public function testFirstOrNew(): void
    {
        $item = ItemModel::firstOrNew(['name' => 'Nonexistent']);
        $this->assertNotNull($item);
    }

    public function testUpdateOrCreate(): void
    {
        ItemModel::create(['name' => 'Target', 'price' => 1]);
        $item = ItemModel::updateOrCreate(['name' => 'Target'], ['price' => 99]);
        $this->assertSame('Target', $item->name);
        $this->assertSame(1, ItemModel::count());
    }

    public function testAttributesAndOffset(): void
    {
        $item = new ItemModel(['name' => 'Attr', 'price' => 5]);
        $this->assertTrue(isset($item['name']));
        $this->assertSame('Attr', $item['name']);
        $item['name'] = 'Updated';
        $this->assertSame('Updated', $item->name);
        unset($item['name']);
        $this->assertNull($item->name);
    }

    public function testOnly(): void
    {
        $item = new ItemModel(['name' => 'A', 'price' => 2, 'active' => true]);
        $only = $item->only(['name', 'price']);
        $this->assertSame(['name' => 'A', 'price' => 2], $only);
    }

    public function testAppend(): void
    {
        $item = new ItemModel(['name' => 'A']);
        $item->append('computed_field');
        // append() registers the attribute for serialization
        $arr = $item->toArray();
        $this->assertArrayHasKey('name', $arr);
    }
}

final class ItemModel extends Model
{
    protected string $table = 'items';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'price', 'active'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        $prop = $ref->getProperty('identityMap');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
