<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Database;
use Siro\Core\Model;
use Siro\Core\Tests\TestCase;

final class SoftDeletesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:', 'charset' => 'utf8']);
        Database::connection()->exec('CREATE TABLE soft_delete_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT \'\',
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT NULL
        )');
    }

    protected function tearDown(): void
    {
        Database::purge();
        parent::tearDown();
    }

    public function testTraitExists(): void
    {
        $this->assertTrue(trait_exists(\Siro\Core\DB\SoftDeletes::class));
    }

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $model = new class extends Model {
            protected string $table = 'soft_delete_models';
            protected array $fillable = ['name'];
            use \Siro\Core\DB\SoftDeletes;
        };

        $saved = $model->create(['name' => 'test']);
        $saved->delete();
        $this->assertNotNull($saved->deleted_at);
    }

    public function testForceDeleteMethodExists(): void
    {
        $model = new class extends Model {
            protected string $table = 'soft_delete_models';
            protected array $fillable = ['name'];
            use \Siro\Core\DB\SoftDeletes;
        };

        $this->assertTrue(method_exists($model, 'forceDelete'));
    }

    public function testRestoreClearsDeletedAt(): void
    {
        $model = new class extends Model {
            protected string $table = 'soft_delete_models';
            protected array $fillable = ['name'];
            use \Siro\Core\DB\SoftDeletes;
        };

        $saved = $model->create(['name' => 'restore_test']);
        $saved->delete();
        $this->assertNotNull($saved->deleted_at);

        $saved->restore();
        $this->assertNull($saved->deleted_at);
    }

    public function testTrashedReturnsCorrectState(): void
    {
        $model = new class extends Model {
            protected string $table = 'soft_delete_models';
            protected array $fillable = ['name'];
            use \Siro\Core\DB\SoftDeletes;
        };

        $saved = $model->create(['name' => 'trashed_test']);
        $this->assertFalse($saved->trashed());

        $saved->delete();
        $this->assertTrue($saved->trashed());

        $saved->restore();
        $this->assertFalse($saved->trashed());
    }
}
