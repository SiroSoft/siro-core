<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

/**
 * Model extra branches: static proxies, loadMissing, update/delete, without.
 */
final class ModelProxyMutationTest extends TestCase
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
        Database::execute('CREATE TABLE mp_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, qty INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        MPItem::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testStaticProxies(): void
    {
        MPItem::create(['name' => 'A', 'qty' => 1]);
        MPItem::create(['name' => 'B', 'qty' => 2]);
        MPItem::create(['name' => 'C', 'qty' => 3]);

        $this->assertSame(3, MPItem::count());
        $this->assertCount(3, MPItem::get());
        $this->assertCount(3, MPItem::all());
        $first = MPItem::first();
        $this->assertNotNull($first);
        $this->assertCount(2, MPItem::limit(2)->get());
        $this->assertCount(2, MPItem::orderBy('id', 'desc')->limit(2)->get());
        $this->assertCount(3, MPItem::where('qty', '>', 0)->get());
        $this->assertCount(1, MPItem::select('id')->limit(1)->get());
    }

    public function testStaticCursor(): void
    {
        MPItem::create(['name' => 'A', 'qty' => 1]);
        $gen = MPItem::cursor();
        $this->assertInstanceOf(\Generator::class, $gen);
        $count = 0;
        foreach ($gen as $item) {
            $count++;
        }
        $this->assertSame(1, $count);
    }

    public function testStaticPaginate(): void
    {
        MPItem::create(['name' => 'A', 'qty' => 1]);
        MPItem::create(['name' => 'B', 'qty' => 2]);
        $page = MPItem::paginate(1, 1);
        $this->assertNotEmpty($page['data']);
    }

    public function testStaticWhereVariants(): void
    {
        MPItem::create(['name' => 'A', 'qty' => 5]);
        $this->assertCount(1, MPItem::where('name', 'A')->get());
        $this->assertCount(1, MPItem::where('qty', '=', 5)->get());
        $this->assertCount(1, MPItem::where('qty', '>', 3)->get());
    }

    public function testUpdateAndDelete(): void
    {
        $item = MPItem::create(['name' => 'A', 'qty' => 1]);
        $ok = $item->update(['name' => 'A2']);
        $this->assertTrue($ok);
        $this->assertSame('A2', MPItem::find($item->id)->name);
        $this->assertTrue($item->delete());
        $this->assertCount(0, MPItem::query()->get());
    }

    public function testWithoutAndWith(): void
    {
        MPItem::create(['name' => 'A', 'qty' => 1]);
        $qb = MPItem::without('rel');
        $this->assertInstanceOf(\Siro\Core\DB\ModelQueryBuilder::class, $qb);
        $this->assertNotEmpty($qb->get());
    }

    public function testLoadMissingAndAppend(): void
    {
        $item = MPItem::create(['name' => 'A', 'qty' => 1]);
        $item->loadMissing('nonexistent');
        $item->append('extra');
        $item->setAttribute('extra', 'x');
        $this->assertTrue(true);
    }

    public function testGetRelationAndSetRelation(): void
    {
        $item = MPItem::create(['name' => 'A', 'qty' => 1]);
        $item->setRelation('rel', ['x' => 1]);
        $this->assertSame(['x' => 1], $item->getRelation('rel'));
    }

    public function testClearIdentityMap(): void
    {
        MPItem::create(['name' => 'A', 'qty' => 1]);
        MPItem::clearIdentityMap();
        $this->assertTrue(true);
    }

    public function testSaveNewModel(): void
    {
        $item = new MPItem();
        $item->setAttribute('name', 'New');
        $item->setAttribute('qty', 9);
        $this->assertTrue($item->save());
        $this->assertSame(1, MPItem::count());
    }
}

final class MPItem extends Model
{
    use SoftDeletes;

    protected string $table = 'mp_items';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'qty'];

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
