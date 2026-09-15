<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * firstArray()/getArray(): plain-array variants of first()/get().
 * Repositories must hand services plain arrays — extra keys assigned onto
 * Model objects are silently swallowed by $fillable (found across ERP
 * repositories as Model-vs-array TypeErrors).
 */
final class ModelArrayHelpersTest extends TestCase
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
        Database::execute('CREATE TABLE arr_widgets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        ArrWidget::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testFirstArrayReturnsPlainArray(): void
    {
        ArrWidget::create(['name' => 'A']);
        $row = ArrWidget::query()->where('name', 'A')->firstArray();
        $this->assertIsArray($row);
        $this->assertSame('A', $row['name']);
    }

    public function testFirstArrayReturnsNullWhenMissing(): void
    {
        $this->assertNull(ArrWidget::query()->where('name', 'nope')->firstArray());
    }

    public function testGetArrayReturnsPlainArrays(): void
    {
        ArrWidget::create(['name' => 'A']);
        ArrWidget::create(['name' => 'B']);
        $rows = ArrWidget::query()->orderBy('name', 'asc')->getArray();
        $this->assertCount(2, $rows);
        $this->assertContainsOnly('array', $rows);
        $this->assertSame(['A', 'B'], array_column($rows, 'name'));
    }

    public function testGetArrayEmptyTableReturnsEmptyArray(): void
    {
        $this->assertSame([], ArrWidget::query()->getArray());
    }

    public function testArrayRowsAcceptNewKeys(): void
    {
        ArrWidget::create(['name' => 'A']);
        $row = ArrWidget::query()->where('name', 'A')->firstArray();
        $this->assertIsArray($row);
        $row['computed'] = 42;
        $this->assertSame(42, $row['computed']);
    }
}

final class ArrWidget extends Model
{
    protected string $table = 'arr_widgets';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public static function resetStatic(): void
    {
        $ref = new \ReflectionClass(self::class);
        $prop = $ref->getProperty('identityMap');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
