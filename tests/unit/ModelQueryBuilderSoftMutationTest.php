<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;
use Siro\Core\DB\SoftDeletes;

/**
 * ModelQueryBuilder soft-delete filters + nested whereHas.
 */
final class ModelQueryBuilderSoftMutationTest extends TestCase
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
        Database::execute('CREATE TABLE ms_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Database::table('ms_users')->insert(['name' => 'Alice']);
        Database::table('ms_users')->insert(['name' => 'Bob']);
        Database::table('ms_users')->insert(['name' => 'Carol']);
        MSUser::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testOnlySoftDeleted(): void
    {
        $u = MSUser::find(1);
        $u->delete();
        $this->assertCount(1, MSUser::query()->onlySoftDeleted()->get());
        $this->assertCount(2, MSUser::query()->get());
    }

    public function testOnlyTrashedAlias(): void
    {
        $u = MSUser::find(1);
        $u->delete();
        $this->assertCount(1, MSUser::query()->onlyTrashed()->get());
    }

    public function testWithTrashedAll(): void
    {
        $u = MSUser::find(1);
        $u->delete();
        $this->assertCount(3, MSUser::query()->withTrashed()->get());
    }

    public function testWithoutSoftDeleteFilter(): void
    {
        $u = MSUser::find(1);
        $u->delete();
        $this->assertCount(3, MSUser::query()->withoutSoftDeleteFilter()->get());
    }

    public function testFindSoftDeletedStillReturns(): void
    {
        $u = MSUser::find(1);
        $u->delete();
        // find() may include soft-deleted via identityMap
        $found = MSUser::find(1);
        $this->assertNotNull($found);
    }

    public function testAllAfterDelete(): void
    {
        $u = MSUser::find(1);
        $u->delete();
        $this->assertCount(2, MSUser::query()->get());
    }
}

final class MSUser extends Model
{
    use SoftDeletes;

    protected string $table = 'ms_users';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

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
