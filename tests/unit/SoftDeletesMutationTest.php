<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Model;

/**
 * Branch coverage for DB\SoftDeletes: forceDelete edge cases, Event cancel.
 */
final class SoftDeletesMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:', 'charset' => 'utf8']);
        Database::connection()->exec('CREATE TABLE sd_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT \'\',
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT NULL
        )');
        Event::flush();
    }

    protected function tearDown(): void
    {
        Event::flush();
        Database::purgeAll();
        parent::tearDown();
    }

    private function makeModel(): Model
    {
        return new class extends Model {
            protected string $table = 'sd_models';
            protected array $fillable = ['name'];
            use \Siro\Core\DB\SoftDeletes;
        };
    }

    public function testForceDeleteOnNewModelReturnsFalse(): void
    {
        $model = $this->makeModel();
        $this->assertFalse($model->forceDelete());
    }

    public function testForceDeletePermanently(): void
    {
        $model = $this->makeModel();
        $saved = $model->create(['name' => 'to-force']);
        $this->assertTrue($saved->forceDelete());
        $this->assertEmpty(Database::table('sd_models')->where('id', '=', $saved->id)->get());
    }

    public function testForceDeleteAfterSoftDelete(): void
    {
        $model = $this->makeModel();
        $saved = $model->create(['name' => 'soft-then-force']);
        $saved->delete();
        $this->assertTrue($saved->trashed());
        $this->assertTrue($saved->forceDelete());
        $this->assertEmpty(Database::table('sd_models')->where('id', '=', $saved->id)->get());
    }

    public function testDeleteCancelledByEvent(): void
    {
        Event::on('sd_models.deleting', static function () {
            return false;
        });

        $model = $this->makeModel();
        $saved = $model->create(['name' => 'cancelled']);
        $result = $saved->delete();
        $this->assertFalse($result);
        $this->assertNull($saved->deleted_at);
    }

    public function testDeleteEmitsDeletedEvent(): void
    {
        $emitted = false;
        Event::on('sd_models.deleted', static function () use (&$emitted) {
            $emitted = true;
            return true;
        });

        $model = $this->makeModel();
        $saved = $model->create(['name' => 'event-check']);
        $result = $saved->delete();
        $this->assertTrue($result);
        $this->assertTrue($emitted);
    }

    public function testTrashedWithEmptyStringIsFalse(): void
    {
        $model = $this->makeModel();
        $saved = $model->create(['name' => 'empty-deleted']);
        $saved->delete();
        $saved->restore();
        $this->assertFalse($saved->trashed());
    }
}
