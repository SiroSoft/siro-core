<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * ModelQueryBuilder tests — real sqlite with User/Post models (hasMany/belongsTo),
 * covering get/find/first/paginate/count/orderBy/where/has/whereHas/cursor/
 * sum/avg/withTrashed and eager loading.
 */
final class ModelQueryBuilderExecuteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
        Database::execute('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            title TEXT,
            views INTEGER DEFAULT 0
        )');
        MqUser::create(['name' => 'Alice', 'email' => 'a@test.com']);
        MqUser::create(['name' => 'Bob', 'email' => 'b@test.com']);
        MqUser::create(['name' => 'Carol', 'email' => 'c@test.com']);
        Database::table('posts')->insert(['user_id' => 1, 'title' => 'Post A', 'views' => 10]);
        Database::table('posts')->insert(['user_id' => 1, 'title' => 'Post B', 'views' => 20]);
        Database::table('posts')->insert(['user_id' => 2, 'title' => 'Post C', 'views' => 5]);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testFindReturnsModel(): void
    {
        $user = MqUser::find(1);
        $this->assertInstanceOf(MqUser::class, $user);
        $this->assertSame('Alice', $user->name);
    }

    public function testFindReturnsNullForMissing(): void
    {
        $this->assertNull(MqUser::find(999));
    }

    public function testGetReturnsModels(): void
    {
        $users = MqUser::query()->get();
        $this->assertCount(3, $users);
        $this->assertInstanceOf(MqUser::class, $users[0]);
    }

    public function testFirstReturnsModel(): void
    {
        $user = MqUser::where('name', '=', 'Bob')->first();
        $this->assertInstanceOf(MqUser::class, $user);
        $this->assertSame('b@test.com', $user->email);
    }

    public function testCount(): void
    {
        $this->assertSame(3, MqUser::count());
    }

    public function testOrderByLimit(): void
    {
        $users = MqUser::orderBy('id', 'desc')->limit(2)->get();
        $this->assertCount(2, $users);
        $this->assertSame('Carol', $users[0]->name);
    }

    public function testPaginate(): void
    {
        $result = MqUser::query()->paginate(2, 1);
        $this->assertCount(2, $result['data']);
        $this->assertSame(3, $result['meta']['total'] ?? $result['total'] ?? 0);
    }

    public function testSumAvg(): void
    {
        $this->assertSame(35, Database::table('posts')->sum('views'));
        $this->assertSame(11, (int) Database::table('posts')->avg('views'));
    }

    public function testHasRelationFilters(): void
    {
        // Users with at least one post
        $users = MqUser::has('posts')->get();
        $this->assertCount(2, $users);
    }

    public function testHasRelationWithCount(): void
    {
        // Users with >= 2 posts (only Alice has 2)
        $users = MqUser::has('posts', '>=', 2)->get();
        $this->assertCount(1, $users);
        $this->assertSame('Alice', $users[0]->name);
    }

    public function testWhereHasRelation(): void
    {
        // Users having a post with views > 10 (Alice's Post B = 20)
        $users = MqUser::whereHas('posts', function ($q) {
            $q->where('views', '>', 10);
        })->get();
        $this->assertCount(1, $users);
        $this->assertSame('Alice', $users[0]->name);
    }

    public function testWhereDoesntHaveRelation(): void
    {
        $users = MqUser::whereDoesntHave('posts')->get();
        $this->assertCount(1, $users);
        $this->assertSame('Carol', $users[0]->name);
    }

    public function testCursorYieldsModels(): void
    {
        $count = 0;
        foreach (MqUser::query()->cursor() as $model) {
            $this->assertInstanceOf(MqUser::class, $model);
            $count++;
        }
        $this->assertSame(3, $count);
    }

    public function testSoftDeleteFilter(): void
    {
        $user = MqUser::find(1);
        $user->delete();
        // Soft-deleted users excluded by default
        $this->assertCount(2, MqUser::query()->get());
        // withTrashed includes them
        $this->assertCount(3, MqUser::query()->withTrashed()->get());
    }

    public function testEagerLoadHasMany(): void
    {
        $users = MqUser::query()->eagerLoad('posts')->get();
        $this->assertCount(3, $users);
        $alice = $users[0];
        $posts = $alice->getRelation('posts');
        $this->assertIsArray($posts);
        $this->assertCount(2, $posts);
    }

    public function testEagerLoadBelongsTo(): void
    {
        $posts = MqPost::query()->eagerLoad('user')->get();
        $this->assertCount(3, $posts);
        $first = $posts[0];
        $user = $first->getRelation('user');
        $this->assertNotNull($user);
        $this->assertSame('Alice', $user->name ?? null);
    }
}

final class MqUser extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'email'];

    use \Siro\Core\DB\SoftDeletes;

    public function posts(): \Siro\Core\DB\Relations\HasMany
    {
        return $this->hasMany(MqPost::class, 'user_id');
    }
}

final class MqPost extends Model
{
    protected string $table = 'posts';

    /** @var array<int, string> */
    protected array $fillable = ['user_id', 'title', 'views'];

    public function user(): \Siro\Core\DB\Relations\BelongsTo
    {
        return $this->belongsTo(MqUser::class, 'user_id');
    }
}
