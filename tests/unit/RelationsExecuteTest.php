<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * Relations tests â€” hasOne, morphMany, morphTo, belongsToMany on real sqlite.
 */
final class RelationsExecuteTest extends TestCase
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
            created_at TEXT,
            updated_at TEXT
        )');
        Database::execute('CREATE TABLE profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            bio TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        Database::execute('CREATE TABLE comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            commentable_type TEXT,
            commentable_id INTEGER,
            body TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        Database::execute('CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        Database::execute('CREATE TABLE roles_users (
            user_id INTEGER,
            role_id INTEGER
        )');

        RelUser::create(['name' => 'Alice']);
        RelUser::create(['name' => 'Bob']);
        Database::table('profiles')->insert(['user_id' => 1, 'bio' => 'Hello']);
        Database::table('comments')->insert(['commentable_type' => 'Siro\\Core\\Tests\\Unit\\RelUser', 'commentable_id' => 1, 'body' => 'First comment']);
        Database::table('comments')->insert(['commentable_type' => 'Siro\\Core\\Tests\\Unit\\RelUser', 'commentable_id' => 1, 'body' => 'Second comment']);
        Database::table('roles')->insert(['name' => 'admin']);
        Database::table('roles')->insert(['name' => 'editor']);
        Database::table('roles_users')->insert(['user_id' => 1, 'role_id' => 1]);
        Database::table('roles_users')->insert(['user_id' => 1, 'role_id' => 2]);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testHasOneRelation(): void
    {
        $profile = RelUser::find(1)->profile()->get();
        $this->assertNotNull($profile);
        $this->assertSame('Hello', $profile->bio);
    }

    public function testHasOneReturnsNullForMissing(): void
    {
        $profile = RelUser::find(2)->profile()->get();
        $this->assertNull($profile);
    }

    public function testMorphManyQuery(): void
    {
        $user = RelUser::find(1);
        $comments = $user->comments()->get();
        $this->assertCount(2, $comments);
    }

    public function testMorphManyCreate(): void
    {
        $user = RelUser::find(1);
        $comment = $user->comments()->create(['body' => 'New comment']);
        $this->assertInstanceOf(RelComment::class, $comment);
        $this->assertSame('New comment', $comment->body);
    }

    public function testMorphToResolvesOwner(): void
    {
        $comment = RelComment::find(1);
        $owner = $comment->commentable()->get();
        $this->assertNotNull($owner);
        $this->assertSame('Alice', $owner->name);
    }

    public function testBelongsToManyGet(): void
    {
        $user = RelUser::find(1);
        $roles = $user->roles()->get();
        $this->assertCount(2, $roles);
        $names = array_map(fn ($r) => $r->name, $roles);
        $this->assertContains('admin', $names);
        $this->assertContains('editor', $names);
    }

    public function testEagerLoadMorphMany(): void
    {
        $users = RelUser::query()->eagerLoad('comments')->get();
        $alice = $users[0];
        $comments = $alice->getRelation('comments');
        $this->assertIsArray($comments);
        $this->assertCount(2, $comments);
    }

    public function testEagerLoadBelongsToMany(): void
    {
        $users = RelUser::query()->eagerLoad('roles')->get();
        $alice = $users[0];
        $roles = $alice->getRelation('roles');
        $this->assertIsArray($roles);
        $this->assertCount(2, $roles);
    }
}

final class RelUser extends Model
{
    protected string $table = 'users';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function profile(): \Siro\Core\DB\Relations\HasOne
    {
        return $this->hasOne(RelProfile::class, 'user_id');
    }

    public function comments(): \Siro\Core\DB\Relations\MorphMany
    {
        return $this->morphMany(RelComment::class, 'commentable');
    }

    public function roles(): \Siro\Core\DB\Relations\BelongsToMany
    {
        return $this->belongsToMany(RelRole::class, 'roles_users', 'user_id', 'role_id');
    }
}

final class RelProfile extends Model
{
    protected string $table = 'profiles';

    /** @var array<int, string> */
    protected array $fillable = ['user_id', 'bio'];
}

final class RelComment extends Model
{
    protected string $table = 'comments';

    /** @var array<int, string> */
    protected array $fillable = ['commentable_type', 'commentable_id', 'body'];

    public function commentable(): \Siro\Core\DB\Relations\MorphTo
    {
        return $this->morphTo('commentable');
    }
}

final class RelRole extends Model
{
    protected string $table = 'roles';

    /** @var array<int, string> */
    protected array $fillable = ['name'];
}
