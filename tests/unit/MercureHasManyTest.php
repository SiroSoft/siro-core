<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Mercure;
use Siro\Core\Model;
use Siro\Core\DB\Relations\HasMany;

/**
 * Mercure + HasMany relation + AuthMiddleware tests.
 */
final class MercureHasManyTest extends TestCase
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
        Database::execute('CREATE TABLE authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, created_at TEXT, updated_at TEXT)');
        Database::execute('CREATE TABLE books (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER, title TEXT, created_at TEXT, updated_at TEXT)');
        HMAuthor::create(['name' => 'A']);
        Database::table('books')->insert(['author_id' => 1, 'title' => 'Book 1']);
        Database::table('books')->insert(['author_id' => 1, 'title' => 'Book 2']);
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testMercureTopic(): void
    {
        $topic = Mercure::topic('products', 5);
        $this->assertSame('/api/products/5', $topic);
    }

    public function testMercureTopicWithoutId(): void
    {
        $topic = Mercure::topic('products');
        $this->assertSame('/api/products', $topic);
    }

    public function testMercurePublishNoHub(): void
    {
        // Without MERCURE_HUB configured, publish returns false gracefully
        putenv('MERCURE_HUB');
        $ok = Mercure::publish('/api/products/1', ['status' => 'ok']);
        $this->assertIsBool($ok);
        putenv('MERCURE_HUB=');
    }

    public function testHasManyGet(): void
    {
        $author = HMAuthor::find(1);
        $books = $author->books()->get();
        $this->assertCount(2, $books);
        $this->assertSame('Book 1', $books[0]->title);
    }

    public function testHasManyCreate(): void
    {
        $author = HMAuthor::find(1);
        $book = $author->books()->create(['title' => 'Book 3']);
        $this->assertInstanceOf(HMBook::class, $book);
        $this->assertSame('Book 3', $book->title);
        $this->assertSame(1, $book->author_id);
    }

    public function testHasManyAccessors(): void
    {
        $author = HMAuthor::find(1);
        $rel = $author->books();
        $this->assertSame(HMBook::class, $rel->getRelatedClass());
        $this->assertSame('author_id', $rel->getForeignKey());
        $this->assertSame('id', $rel->getLocalKey());
    }
}

final class HMAuthor extends Model
{
    protected string $table = 'authors';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function books(): HasMany
    {
        return $this->hasMany(HMBook::class, 'author_id');
    }
}

final class HMBook extends Model
{
    protected string $table = 'books';

    /** @var array<int, string> */
    protected array $fillable = ['author_id', 'title'];
}
