<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

/**
 * Schema database-backed tests — real sqlite.
 * Covers Schema::create/table/drop/dropIfExists/dropColumn/renameColumn/
 * rename/hasTable/getColumnListing/hasColumn/hasDatabase.
 */
final class SchemaDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
            'slow_query_threshold' => 500,
        ]);
        Schema::resetPdo();
        Schema::connect(Database::connection());
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        Schema::resetPdo();
        parent::tearDown();
    }

    public function testCreateAndHasTable(): void
    {
        Schema::create('widgets', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('qty')->default(0);
            $t->timestamps();
        });
        $this->assertTrue(Schema::hasTable('widgets'));
        $this->assertFalse(Schema::hasTable('nonexistent'));
    }

    public function testGetColumnListing(): void
    {
        Schema::create('widgets', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $cols = Schema::getColumnListing('widgets');
        $this->assertContains('id', $cols);
        $this->assertContains('name', $cols);
    }

    public function testHasColumn(): void
    {
        Schema::create('widgets', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $this->assertTrue(Schema::hasColumn('widgets', 'name'));
        $this->assertFalse(Schema::hasColumn('widgets', 'missing'));
    }

    public function testDrop(): void
    {
        Schema::create('temp_tbl', function (Blueprint $t) {
            $t->id();
        });
        $this->assertTrue(Schema::hasTable('temp_tbl'));
        Schema::drop('temp_tbl');
        $this->assertFalse(Schema::hasTable('temp_tbl'));
    }

    public function testDropIfExists(): void
    {
        Schema::create('temp2', function (Blueprint $t) {
            $t->id();
        });
        Schema::dropIfExists('temp2');
        $this->assertFalse(Schema::hasTable('temp2'));
        // Should not throw for missing table
        Schema::dropIfExists('never-existed');
        $this->assertTrue(true);
    }

    public function testTableAlterAddColumn(): void
    {
        Schema::create('alt_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::table('alt_tbl', function (Blueprint $t) {
            $t->integer('age')->nullable();
        });
        $this->assertTrue(Schema::hasColumn('alt_tbl', 'age'));
    }

    public function testDropColumn(): void
    {
        Schema::create('dc_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('keep');
            $t->string('remove_me');
        });
        Schema::dropColumn('dc_tbl', 'remove_me');
        $this->assertFalse(Schema::hasColumn('dc_tbl', 'remove_me'));
        $this->assertTrue(Schema::hasColumn('dc_tbl', 'keep'));
    }

    public function testRename(): void
    {
        Schema::create('old_name', function (Blueprint $t) {
            $t->id();
        });
        Schema::rename('old_name', 'new_name');
        $this->assertFalse(Schema::hasTable('old_name'));
        $this->assertTrue(Schema::hasTable('new_name'));
    }

    public function testRenameColumn(): void
    {
        Schema::create('rc_tbl', function (Blueprint $t) {
            $t->id();
            $t->string('old_col');
        });
        Schema::renameColumn('rc_tbl', 'old_col', 'new_col');
        $this->assertFalse(Schema::hasColumn('rc_tbl', 'old_col'));
        $this->assertTrue(Schema::hasColumn('rc_tbl', 'new_col'));
    }

    public function testHasDatabase(): void
    {
        // SQLite: main database exists
        $this->assertTrue(Schema::hasDatabase('main'));
    }
}
