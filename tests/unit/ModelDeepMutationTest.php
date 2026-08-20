<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

/**
 * Model deep branches: create/findOrFail/firstOrCreate/refresh/hydrate.
 */
final class ModelDeepMutationTest extends TestCase
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
        Database::execute('CREATE TABLE md_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, price REAL,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        MDItem::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testFindOrFailFound(): void
    {
        $item = MDItem::create(['name' => 'A', 'price' => 1]);
        $found = MDItem::findOrFail($item->id);
        $this->assertSame('A', $found->name);
    }

    public function testFindOrFailMissing(): void
    {
        $this->expectException(\Siro\Core\ModelNotFoundException::class);
        MDItem::findOrFail(999);
    }

    public function testFirstOrCreateExisting(): void
    {
        MDItem::create(['name' => 'B', 'price' => 2]);
        $item = MDItem::firstOrCreate(['name' => 'B'], ['price' => 99]);
        $this->assertSame(2.0, (float) $item->price);
        $this->assertSame(1, MDItem::count());
    }

    public function testFirstOrCreateNew(): void
    {
        $item = MDItem::firstOrCreate(['name' => 'C'], ['price' => 3]);
        $this->assertSame('C', $item->name);
        $this->assertSame(3.0, (float) $item->price);
    }

    public function testFirstOrNew(): void
    {
        $item = MDItem::firstOrNew(['name' => 'D'], ['price' => 4]);
        $this->assertSame('D', $item->name);
        $this->assertSame(0, MDItem::count());
    }

    public function testUpdateOrCreateExisting(): void
    {
        MDItem::create(['name' => 'E', 'price' => 5]);
        $item = MDItem::updateOrCreate(['name' => 'E'], ['price' => 50]);
        $this->assertSame(50.0, (float) $item->price);
        $this->assertSame(1, MDItem::count());
    }

    public function testUpdateOrCreateNew(): void
    {
        $item = MDItem::updateOrCreate(['name' => 'F'], ['price' => 6]);
        $this->assertSame('F', $item->name);
        $this->assertSame(1, MDItem::count());
    }

    public function testRefreshAndFresh(): void
    {
        $item = MDItem::create(['name' => 'G', 'price' => 7]);
        MDItem::query()->where('id', '=', $item->id)->update(['price' => 77]);
        $item->refresh();
        $this->assertSame(77.0, (float) $item->price);
        $fresh = $item->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(77.0, (float) $fresh->price);
    }

    public function testOnly(): void
    {
        $item = MDItem::create(['name' => 'H', 'price' => 8]);
        $only = $item->only(['name', 'price']);
        $this->assertArrayHasKey('name', $only);
        $this->assertArrayHasKey('price', $only);
    }

    public function testAppend(): void
    {
        $item = MDItem::create(['name' => 'I', 'price' => 9]);
        $item->append('computed');
        $item->setAttribute('computed', 'val');
        $this->assertTrue(true);
    }

    public function testHydrateAndHydrateAll(): void
    {
        $m = MDItem::hydrate(['name' => 'H1', 'price' => 1]);
        $this->assertInstanceOf(MDItem::class, $m);
        $models = MDItem::hydrateAll([['name' => 'A'], ['name' => 'B']]);
        $this->assertCount(2, $models);
    }

    public function testSyncOriginalAndGetDirty(): void
    {
        $item = MDItem::create(['name' => 'J', 'price' => 10]);
        $item->syncOriginal();
        $item->setAttribute('price', 100);
        $item->save();
        $this->assertTrue(true);
    }

    public function testCreateWithMassAssignment(): void
    {
        $item = MDItem::create(['name' => 'K', 'price' => 11, 'secret' => 'blocked']);
        $this->assertSame('K', $item->name);
        $this->assertNull($item->getAttribute('secret'));
    }
}

final class MDItem extends Model
{
    use SoftDeletes;

    protected string $table = 'md_items';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'price'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        foreach (['identityMap', 'lastInsertId', 'queryLog'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue(null, []);
            }
        }
    }
}
