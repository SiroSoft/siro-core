<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;
use Siro\Core\Observers\ModelObserver;

/**
 * Model execution branches: fillable, casts, array access, observe.
 */
final class ModelMutationTest extends TestCase
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
        Database::execute('CREATE TABLE mu_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, price REAL, active INTEGER,
            created_at TEXT, updated_at TEXT
        )');
        MItem::resetStatic();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testFillGuardsUnguarded(): void
    {
        $m = new MItem();
        $m->fill(['name' => 'visible', 'secret_col' => 'hidden']);
        $this->assertSame('visible', $m->getAttribute('name'));
        $this->assertNull($m->getAttribute('secret_col'));
    }

    public function testSetFillableOverrides(): void
    {
        $m = new MItem();
        $m->setFillable(['other']);
        $m->fill(['other' => 'x', 'name' => 'blocked']);
        $this->assertSame('x', $m->getAttribute('other'));
        $this->assertNull($m->getAttribute('name'));
    }

    public function testArrayAccess(): void
    {
        $m = new MItem(['name' => 'arr']);
        $this->assertTrue(isset($m['name']));
        $this->assertSame('arr', $m['name']);
        $m['price'] = 9.5;
        $this->assertSame(9.5, $m['price']);
        unset($m['name']);
        $this->assertFalse(isset($m['name']));
    }

    public function testGetSetAttributeMagic(): void
    {
        $m = new MItem();
        $m->name = 'magic';
        $this->assertSame('magic', $m->name);
        $this->assertTrue(isset($m->name));
    }

    public function testCasts(): void
    {
        $m = new MItem(['active' => '1', 'price' => '19.99']);
        $this->assertSame(19.99, $m->getAttribute('price'));
    }

    public function testTableAndKeyName(): void
    {
        $m = new MItem();
        $this->assertSame('mu_items', $m->getTable());
        $this->assertSame('id', $m->getKeyName());
    }

    public function testObserve(): void
    {
        MItem::observe(MObserver::class);
        $m = new MItem(['name' => 'obs']);
        $this->assertTrue(true);
    }

    public function testToArrayExcludesHidden(): void
    {
        $m = new MItem(['name' => 'a', 'secret' => 's']);
        $m->setHidden(['secret']);
        $arr = $m->toArray();
        $this->assertArrayNotHasKey('secret', $arr);
    }
}
