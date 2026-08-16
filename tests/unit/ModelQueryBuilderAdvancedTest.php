<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

/**
 * ModelQueryBuilder advanced: relations (hasMany/belongsTo), whereHas/has,
 * withCount, eagerLoad, soft deletes, cursor, paginate.
 */
final class ModelQueryBuilderAdvancedTest extends TestCase
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
        Database::execute('CREATE TABLE mq_owners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
        Database::execute('CREATE TABLE mq_pets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id INTEGER,
            name TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
        Database::table('mq_owners')->insert(['name' => 'Alice']);
        Database::table('mq_owners')->insert(['name' => 'Bob']);
        Database::table('mq_pets')->insert(['owner_id' => 1, 'name' => 'Rex']);
        Database::table('mq_pets')->insert(['owner_id' => 1, 'name' => 'Milo']);
        Database::table('mq_pets')->insert(['owner_id' => 2, 'name' => 'Buddy']);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testFindReturnsModel(): void
    {
        $owner = MQOwner::find(1);
        $this->assertInstanceOf(MQOwner::class, $owner);
        $this->assertSame('Alice', $owner->name);
    }

    public function testHasManyRelation(): void
    {
        $owner = MQOwner::find(1);
        $pets = $owner->pets()->get();
        $this->assertCount(2, $pets);
    }

    public function testWithCount(): void
    {
        $owners = MQOwner::query()->withCount('pets')->get();
        $this->assertCount(2, $owners);
    }

    public function testWithCountAlias(): void
    {
        $owners = MQOwner::query()->withCount('pets as pet_count')->get();
        $this->assertCount(2, $owners);
    }

    public function testEagerLoad(): void
    {
        $owners = MQOwner::query()->eagerLoad('pets')->get();
        $this->assertCount(2, $owners);
    }

    public function testHasRelation(): void
    {
        $owners = MQOwner::query()->has('pets')->get();
        $this->assertCount(2, $owners);
    }

    public function testWhereHas(): void
    {
        $owners = MQOwner::query()->whereHas('pets', function ($q) {
            $q->where('name', 'Rex');
        })->get();
        $this->assertCount(1, $owners);
        $this->assertSame('Alice', $owners[0]->name);
    }

    public function testOrWhereHas(): void
    {
        $owners = MQOwner::query()
            ->where('name', 'Nonexistent')
            ->orWhereHas('pets', function ($q) {
                $q->where('name', 'Buddy');
            })->get();
        $this->assertCount(1, $owners);
    }

    public function testWhereDoesntHave(): void
    {
        Database::table('mq_owners')->insert(['name' => 'Charlie']);
        $owners = MQOwner::query()->whereDoesntHave('pets')->get();
        $this->assertCount(1, $owners);
        $this->assertSame('Charlie', $owners[0]->name);
    }

    public function testHasCountOperator(): void
    {
        $owners = MQOwner::query()->has('pets', '>=', 2)->get();
        $this->assertCount(1, $owners);
    }

    public function testNestedHasThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        MQOwner::query()->has('pets.owner')->get();
    }

    public function testSoftDeleteScope(): void
    {
        Database::execute('UPDATE mq_owners SET deleted_at = ? WHERE id = 2', [date('Y-m-d H:i:s')]);
        // Default: excludes soft-deleted
        $owners = MQOwner::query()->get();
        $this->assertCount(1, $owners);
        // withTrashed: includes
        $all = MQOwner::query()->withTrashed()->get();
        $this->assertCount(2, $all);
        // onlyTrashed: only deleted
        $trashed = MQOwner::query()->onlyTrashed()->get();
        $this->assertCount(1, $trashed);
    }

    public function testCursorYieldsModels(): void
    {
        $count = 0;
        foreach (MQOwner::query()->cursor() as $model) {
            $this->assertInstanceOf(MQOwner::class, $model);
            $count++;
        }
        $this->assertSame(2, $count);
    }

    public function testPaginate(): void
    {
        $result = MQOwner::query()->paginate(1, 1);
        $this->assertNotEmpty($result['data']);
    }

    public function testSelectAndOrderBy(): void
    {
        $rows = MQOwner::query()->select('id', 'name')->orderBy('name', 'desc')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]->name);
    }

    public function testLimitAndOffset(): void
    {
        $rows = MQOwner::query()->orderBy('id', 'asc')->limit(1)->offset(1)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]->name);
    }

    public function testWithTrashed(): void
    {
        $pet = MQPet::find(1);
        $pet->delete();
        $this->assertTrue($pet->trashed());
        $this->assertCount(1, MQPet::query()->onlyTrashed()->get());
        $this->assertCount(3, MQPet::query()->withTrashed()->get());
        $withTrashed = MQPet::query()->withTrashed()->find(1);
        $this->assertNotNull($withTrashed);
        $this->assertTrue($withTrashed->trashed());
    }

    public function testOrHas(): void
    {
        $rows = MQOwner::query()->orHas('pets', '>=', 2)->get();
        $this->assertNotEmpty($rows);
    }

    public function testWhereHasWithCallback(): void
    {
        $rows = MQOwner::query()
            ->whereHas('pets', function ($q) {
                $q->where('name', '=', 'Rex');
            })
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testEagerLoadWithColumns(): void
    {
        $owners = MQOwner::query()->eagerLoad('pets', ['id', 'name'])->get();
        $this->assertCount(2, $owners);
        $this->assertCount(2, $owners[0]->getRelation('pets'));
    }

    public function testWithCountCallback(): void
    {
        $owners = MQOwner::query()->withCount('pets', function ($q) {
            $q->where('name', '!=', 'Buddy');
        })->get();
        $this->assertCount(2, $owners);
    }

    public function testFirstAndCount(): void
    {
        $this->assertSame(3, MQPet::query()->count());
        $first = MQPet::query()->orderBy('id', 'asc')->first();
        $this->assertSame('Rex', $first->name);
    }

    public function testWhereDoesntHaveOr(): void
    {
        // Insert an owner with no pets
        Database::table('mq_owners')->insert(['name' => 'Carol']);
        $rows = MQOwner::query()->orWhereDoesntHave('pets')->get();
        $this->assertNotEmpty($rows);
    }
}

final class MQOwner extends Model
{
    use SoftDeletes;

    protected string $table = 'mq_owners';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function pets(): \Siro\Core\DB\Relations\HasMany
    {
        return $this->hasMany(MQPet::class, 'owner_id');
    }
}

final class MQPet extends Model
{
    use SoftDeletes;

    protected string $table = 'mq_pets';

    /** @var array<int, string> */
    protected array $fillable = ['owner_id', 'name'];
}
