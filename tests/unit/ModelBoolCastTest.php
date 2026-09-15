<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * Regression: PHP `false` binds as `''` under PDO MySQL, which fails strict
 * mode (1366) on boolean/tinyint columns (found via ERP prod: saving
 * `track_inventory=false` 500d). Model::save() must normalize `bool`-cast
 * attributes to 0/1 for the database.
 */
final class ModelBoolCastTest extends TestCase
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
        Database::execute('CREATE TABLE flags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            active INTEGER DEFAULT 1,
            created_at TEXT,
            updated_at TEXT
        )');
        FlagModel::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testFalseBoolCastStoresZero(): void
    {
        $item = FlagModel::create(['name' => 'A', 'active' => false]);
        $raw = Database::connection()->query('SELECT active FROM flags WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame(0, (int) $raw);
        $found = FlagModel::find($item->id);
        $this->assertNotNull($found);
        $this->assertFalse($found->active);
    }

    public function testTrueBoolCastStoresOne(): void
    {
        $item = FlagModel::create(['name' => 'B', 'active' => true]);
        $raw = Database::connection()->query('SELECT active FROM flags WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame(1, (int) $raw);
    }

    public function testUpdateNormalizesBoolToo(): void
    {
        $item = FlagModel::create(['name' => 'C', 'active' => true]);
        $item->fill(['active' => false]);
        $this->assertTrue($item->save());
        $raw = Database::connection()->query('SELECT active FROM flags WHERE id = ' . (int) $item->id)
            ->fetchColumn();
        $this->assertSame(0, (int) $raw);
    }

    public function testNonBoolCastsUntouched(): void
    {
        $m = new FlagModel();
        $ref = new \ReflectionMethod($m, 'castForDatabase');
        $ref->setAccessible(true);
        $out = $ref->invoke($m, ['name' => 'x', 'price' => '9.5', 'active' => false, 'note' => null]);
        $this->assertSame('x', $out['name']);
        $this->assertSame('9.5', $out['price']);
        $this->assertSame(0, $out['active']);
        $this->assertNull($out['note']);
    }
}

final class FlagModel extends Model
{
    protected string $table = 'flags';

    /** @var array<int, string> */
    protected array $fillable = ['name', 'price', 'active'];

    /** @var array<string, string> */
    protected array $casts = ['active' => 'bool'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        $prop = $ref->getProperty('identityMap');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
