<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\DB\EagerLoader;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * Coverage tests for DB\EagerLoader across all relation types.
 */
final class EagerLoaderMutationTest extends TestCase
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
        Database::execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, ' .
            'created_at TEXT, updated_at ' . 'TEXT)');
        Database::execute('CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, bio TEXT)');
        Database::execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT)');
        Database::execute('CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, commentable_type ' .
            'TEXT, commentable_id INTEGER, body ' . 'TEXT)');
        Database::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        Database::execute('CREATE TABLE roles_users (user_id INTEGER, role_id INTEGER)');

        Database::table('users')->insert(['name' => 'Alice']);
        Database::table('users')->insert(['name' => 'Bob']);
        Database::table('profiles')->insert(['user_id' => 1, 'bio' => 'Hello']);
        Database::table('posts')->insert(['user_id' => 1, 'title' => 'P1']);
        Database::table('posts')->insert(['user_id' => 1, 'title' => 'P2']);
        Database::table('comments')->insert(['commentable_type' => ElUser::class, 'commentable_id' => 1,
            'body' => 'C1']);
        Database::table('roles')->insert(['name' => 'admin']);
        Database::table('roles_users')->insert(['user_id' => 1, 'role_id' => 1]);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testLoadBatchEmpty(): void
    {
        $loader = new EagerLoader(ElUser::class);
        $loader->loadBatch([], ['posts' => ['*']]);
        $this->assertTrue(true);
    }

    public function testLoadHasMany(): void
    {
        $user = ElUser::find(1);
        $loader = new EagerLoader(ElUser::class);
        $loader->load($user, ['posts' => ['*']]);
        $this->assertCount(2, $user->getRelation("posts"));
    }

    public function testLoadHasManySpecificColumns(): void
    {
        $user = ElUser::find(1);
        $loader = new EagerLoader(ElUser::class);
        $loader->load($user, ['posts' => ['id', 'title']]);
        $this->assertCount(2, $user->getRelation("posts"));
    }

    public function testLoadBelongsTo(): void
    {
        $post = ElPost::find(1);
        $loader = new EagerLoader(ElPost::class);
        $loader->load($post, ['owner' => ['*']]);
        $this->assertNotNull($post->getRelation("owner"));
        $this->assertSame('Alice', $post->getRelation("owner")->name);
    }

    public function testLoadHasOne(): void
    {
        $user = ElUser::find(1);
        $loader = new EagerLoader(ElUser::class);
        $loader->load($user, ['profile' => ['*']]);
        $this->assertNotNull($user->getRelation("profile"));
        $this->assertSame('Hello', $user->getRelation("profile")->bio);
    }

    public function testLoadBelongsToMany(): void
    {
        $user = ElUser::find(1);
        $loader = new EagerLoader(ElUser::class);
        $loader->load($user, ['roles' => ['*']]);
        $this->assertCount(1, $user->getRelation("roles"));
        $this->assertSame('admin', $user->getRelation("roles")[0]['name']);
    }

    public function testLoadMorphMany(): void
    {
        $user = ElUser::find(1);
        $loader = new EagerLoader(ElUser::class);
        $loader->load($user, ['comments' => ['*']]);
        $this->assertCount(1, $user->getRelation("comments"));
    }

    public function testLoadMorphTo(): void
    {
        $comment = ElComment::find(1);
        $loader = new EagerLoader(ElComment::class);
        $loader->load($comment, ['commentable' => ['*']]);
        $this->assertNotNull($comment->getRelation("commentable"));
    }

    public function testMissingRelationMethod(): void
    {
        $user = ElUser::find(1);
        $loader = new EagerLoader(ElUser::class);
        $loader->load($user, ['nonexistent' => ['*']]);
        $this->assertTrue(true);
    }

    public function testLoadBatchMultiple(): void
    {
        $users = ElUser::query()->get();
        $loader = new EagerLoader(ElUser::class);
        $loader->loadBatch($users, ['posts' => ['*'], 'roles' => ['*']]);
        $this->assertCount(2, $users);
    }

    public function testModelWithIsAppliedToGetPaginateAndCursorPaginate(): void
    {
        Database::enableQueryCapture(true);
        $users = ElWithUser::query()->get();
        $this->assertNotNull($users[0]->getRelation('posts'));
        $page = ElWithUser::query()->paginate(1, 1);
        $this->assertNotNull($page['data'][0]->getRelation('posts'));
        $cursor = ElWithUser::query()->cursorPaginate(1);
        $this->assertNotNull($cursor['data'][0]->getRelation('posts'));
        $this->assertLessThanOrEqual(9, count(Database::getCapturedQueries()));
        Database::enableQueryCapture(false);
    }
}
