<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * JSON-cast compat hardening: a `'json'` cast must never swallow data.
 * Read: a string that is not valid JSON comes back as the same string
 * (not `[]`); valid JSON decodes (including `null` for `'null'` and
 * scalars for `'"x"'`/`'123'`/`'true'`). Write: a plain string is encoded
 * as a JSON scalar so it round-trips; a valid JSON string stays as-is.
 * Mirrors ModelJsonCastTest (sqlite :memory:).
 */
final class ModelJsonCastCompatTest extends TestCase
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
        Database::execute('CREATE TABLE json_compat_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            meta TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        JsonCastCompatModel::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    // ---- READ (via reflection on castAttribute) ----

    #[DataProvider('readCases')]
    public function testReadMatrix(mixed $stored, mixed $expected): void
    {
        $m = new JsonCastCompatModel();
        $ref = new \ReflectionMethod($m, 'castAttribute');
        $ref->setAccessible(true);
        $this->assertSame($expected, $ref->invoke($m, 'meta', $stored));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function readCases(): array
    {
        return [
            'json object string' => ['{"a":1}', ['a' => 1]],
            'json list string' => ['[1,2]', [1, 2]],
            'json string scalar' => ['"abc"', 'abc'],
            'json int scalar' => ['123', 123],
            'json bool scalar' => ['true', true],
            'json null literal' => ['null', null],
            'invalid json' => ['not-json{', 'not-json{'],
            'empty string' => ['', ''],
            'plain string' => ['hello', 'hello'],
            'array passthrough' => [['a' => 1], ['a' => 1]],
            'null stays null' => [null, null],
            'int passthrough' => [5, 5],
        ];
    }

    // ---- WRITE (via reflection on castForDatabase) ----

    #[DataProvider('writeCases')]
    public function testWriteMatrix(mixed $value, mixed $expected): void
    {
        $m = new JsonCastCompatModel();
        $ref = new \ReflectionMethod($m, 'castForDatabase');
        $ref->setAccessible(true);
        $out = $ref->invoke($m, ['meta' => $value]);
        $this->assertSame($expected, $out['meta']);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function writeCases(): array
    {
        return [
            'valid json string kept as-is' => ['{"a":1}', '{"a":1}'],
            'array encoded' => [['a' => 1], '{"a":1}'],
            'object encoded' => [(object) ['a' => 1], '{"a":1}'],
            'plain string encoded as scalar' => ['hello', '"hello"'],
            'empty string encoded as scalar' => ['', '""'],
            'null stays null' => [null, null],
            'int stays int' => [5, 5],
            'unencodable array falls back to []' => [['x' => INF], '[]'],
        ];
    }

    // ---- ROUND-TRIP (sqlite end-to-end, raw value checked) ----

    public function testRoundTripArray(): void
    {
        $item = JsonCastCompatModel::create(['name' => 'A', 'meta' => ['a' => 1]]);
        $raw = Database::connection()->query('SELECT meta FROM json_compat_items WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame('{"a":1}', $raw);
        $found = JsonCastCompatModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertSame(['a' => 1], $found->meta);
    }

    public function testRoundTripJsonString(): void
    {
        $item = JsonCastCompatModel::create(['name' => 'B', 'meta' => '{"k":"v"}']);
        $raw = Database::connection()->query('SELECT meta FROM json_compat_items WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame('{"k":"v"}', $raw);
        $found = JsonCastCompatModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertSame(['k' => 'v'], $found->meta);
    }

    public function testRoundTripPlainString(): void
    {
        $item = JsonCastCompatModel::create(['name' => 'C', 'meta' => 'hello']);
        $raw = Database::connection()->query('SELECT meta FROM json_compat_items WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame('"hello"', $raw);
        $found = JsonCastCompatModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertSame('hello', $found->meta);
    }

    public function testRoundTripNull(): void
    {
        $item = JsonCastCompatModel::create(['name' => 'D', 'meta' => null]);
        $raw = Database::connection()->query('SELECT meta FROM json_compat_items WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertNull($raw);
        $found = JsonCastCompatModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertNull($found->meta);
    }

    public function testRoundTripLegacyPlainTextRow(): void
    {
        // A legacy row written before the cast existed (raw plain text).
        Database::execute("INSERT INTO json_compat_items (name, meta) VALUES ('E', 'not-json{')");
        $id = (int) Database::connection()->lastInsertId();
        $found = JsonCastCompatModel::find($id);
        $this->assertNotNull($found);
        $this->assertSame('not-json{', $found->meta);
    }
}

final class JsonCastCompatModel extends Model
{
    protected string $table = 'json_compat_items';

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
