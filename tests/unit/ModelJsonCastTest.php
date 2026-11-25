<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * P0-3: a `'json'` cast must actually work — decode on read (assoc array,
 * like the `'array'` cast) and encode on write (array/object become a JSON
 * string before binding; a string is already JSON and stays; null stays).
 * Mirrors ModelBoolCastTest (sqlite :memory:).
 */
final class ModelJsonCastTest extends TestCase
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
        Database::execute('CREATE TABLE json_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            meta TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        JsonCastModel::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testArrayCastStoresJsonString(): void
    {
        $item = JsonCastModel::create(['name' => 'A', 'meta' => ['a' => 1, 'b' => ['x']]]);
        $raw = Database::connection()->query('SELECT meta FROM json_items WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame('{"a":1,"b":["x"]}', $raw);
        $found = JsonCastModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertSame(['a' => 1, 'b' => ['x']], $found->meta);
    }

    public function testJsonStringStoredAsIs(): void
    {
        $item = JsonCastModel::create(['name' => 'B', 'meta' => '{"k":"v"}']);
        $raw = Database::connection()->query('SELECT meta FROM json_items WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame('{"k":"v"}', $raw);
        $found = JsonCastModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertSame(['k' => 'v'], $found->meta);
    }

    public function testNullStaysNull(): void
    {
        $item = JsonCastModel::create(['name' => 'C', 'meta' => null]);
        $raw = Database::connection()->query('SELECT meta FROM json_items WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertNull($raw);
        $found = JsonCastModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertNull($found->meta);
    }

    public function testUpdateNormalizesArrayToo(): void
    {
        $item = JsonCastModel::create(['name' => 'D', 'meta' => ['n' => 1]]);
        $item->fill(['meta' => ['n' => 2]]);
        $this->assertTrue($item->save());
        $raw = Database::connection()->query('SELECT meta FROM json_items WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame('{"n":2}', $raw);
    }

    public function testInvalidJsonStringReadsAsString(): void
    {
        $item = JsonCastModel::create(['name' => 'E', 'meta' => 'not-json{']);
        $found = JsonCastModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertSame('not-json{', $found->meta);
    }

    public function testToArrayReturnsArrayForJsonCast(): void
    {
        $item = JsonCastModel::create(['name' => 'F', 'meta' => ['x' => true]]);
        $this->assertSame(['x' => true], $item->toArray()['meta']);
        $this->assertSame(['x' => true], $item->jsonSerialize()['meta']);
    }

    public function testCastForDatabaseEncodesJson(): void
    {
        $m = new JsonCastModel();
        $ref = new \ReflectionMethod($m, 'castForDatabase');
        $ref->setAccessible(true);
        $out = $ref->invoke($m, [
            'name' => 'x',
            'meta' => ['a' => 1],
            'note' => null,
        ]);
        $this->assertSame('x', $out['name']);
        $this->assertSame('{"a":1}', $out['meta']);
        $this->assertNull($out['note']);
    }

    public function testCastForDatabaseLeavesStringAndNullAlone(): void
    {
        $m = new JsonCastModel();
        $ref = new \ReflectionMethod($m, 'castForDatabase');
        $ref->setAccessible(true);
        $out = $ref->invoke($m, ['meta' => '{"a":1}']);
        $this->assertSame('{"a":1}', $out['meta']);
        $out = $ref->invoke($m, ['meta' => null]);
        $this->assertNull($out['meta']);
    }
}

final class JsonCastModel extends Model
{
    protected string $table = 'json_items';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'meta'];

    /** @var array<string, string> */
    protected array $casts = ['meta' => 'json'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        $prop = $ref->getProperty('identityMap');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
