<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

/**
 * ModelQueryBuilder relation branches: whereHas with HasMany, nested.
 */
final class ModelQueryBuilderRelMutationTest extends TestCase
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
        Database::execute('CREATE TABLE mr_owners (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Database::execute('CREATE TABLE mr_pets (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INTEGER, name TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Database::table('mr_owners')->insert(['name' => 'Alice']);
        Database::table('mr_owners')->insert(['name' => 'Bob']);
        Database::table('mr_pets')->insert(['owner_id' => 1, 'name' => 'Rex']);
        Database::table('mr_pets')->insert(['owner_id' => 1, 'name' => 'Milo']);
        MROwner::resetStatic();
        MRPet::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testWhereHasHasManyCountOperator(): void
    {
        $rows = MROwner::query()->has('pets', '=', 2)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testWhereHasHasManyExists(): void
    {
        $rows = MROwner::query()->has('pets')->get();
        $this->assertCount(1, $rows);
    }

    public function testWhereHasNestedCallback(): void
    {
        $rows = MROwner::query()
            ->whereHas('pets', function ($q) {
                $q->where('name', '=', 'Rex');
            })
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testWhereDoesntHaveHasMany(): void
    {
        $rows = MROwner::query()->whereDoesntHave('pets')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]->name);
    }

    public function testOrWhereHas(): void
    {
        $rows = MROwner::query()->where('name', '=', 'Bob')->orWhereHas('pets')->get();
        $this->assertCount(2, $rows);
    }

    public function testLoadCountsOnRelation(): void
    {
        $owners = MROwner::query()->get();
        MROwner::query()->loadCountsIntoModels($owners);
        $this->assertCount(2, $owners);
    }

    public function testEagerLoadHasMany(): void
    {
        $owners = MROwner::query()->eagerLoad('pets')->get();
        $this->assertCount(2, $owners);
        $this->assertCount(2, $owners[0]->getRelation('pets'));
    }

    public function testHydrateAllModels(): void
    {
        $rows = MROwner::query()->get();
        $this->assertCount(2, $rows);
    }

    public function testFindReturnsNull(): void
    {
        $this->assertNull(MROwner::find(999));
    }
}

final class MROwner extends Model
{
    use SoftDeletes;

    protected string $table = 'mr_owners';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function pets(): \Siro\Core\DB\Relations\HasMany
    {
        return $this->hasMany(MRPet::class, 'owner_id');
    }

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

final class MRPet extends Model
{
    use SoftDeletes;

    protected string $table = 'mr_pets';

    /** @var array<int, string> */
    protected array $fillable = ['owner_id', 'name'];

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
