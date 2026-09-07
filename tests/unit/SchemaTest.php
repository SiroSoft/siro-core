<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\DB\Blueprint;
use Siro\Core\DB\Column;

final class SchemaTest extends TestCase
{
    public function testIdAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->id();
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testStringAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->string('name');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testIntegerAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->integer('count');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testBigintAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->bigint('views');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testTextAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->text('description');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testBooleanAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->boolean('active');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testDecimalAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->decimal('price', 10, 2);
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testTimestampsAddsColumns(): void
    {
        $b = new Blueprint('test');
        $b->timestamps();
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testSoftDeletesAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->softDeletes();
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testFloatAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->float('rating');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testJsonAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->json('metadata');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testDateAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->date('birth_date');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testDatetimeAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->datetime('published_at');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testSmallintAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->smallint('qty');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testRememberTokenAddsColumn(): void
    {
        $b = new Blueprint('test');
        $b->rememberToken();
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testUniqueConstraint(): void
    {
        $b = new Blueprint('test');
        $b->string('email');
        $b->unique('email');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testIndexConstraint(): void
    {
        $b = new Blueprint('test');
        $b->string('email');
        $b->index('email');
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testPrimaryKey(): void
    {
        $b = new Blueprint('test');
        $b->id();
        $sql = $b->compileCreate();
        $this->assertNotEmpty($sql);
    }

    public function testCompositePrimaryKey(): void
    {
        $b = new Blueprint('test', 'mysql');
        $b->integer('order_id');
        $b->integer('product_id');
        $b->primary(['order_id', 'product_id']);
        $sql = $b->compileCreate();
        $this->assertSame(
            "CREATE TABLE IF NOT EXISTS `test` (\n  `order_id` INT NOT NULL,\n  `product_id` INT NOT NULL,\n  PRIMARY KEY (`order_id`, `product_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            $sql[0]
        );
    }

    public function testDefaultBooleanFalse(): void
    {
        $b = new Blueprint('test');
        $b->boolean('is_active')->default(false);
        $sql = $b->compileCreate();
        $this->assertStringContainsString("DEFAULT 0", $sql[0]);
    }

    public function testDefaultBooleanTrue(): void
    {
        $b = new Blueprint('test');
        $b->boolean('is_admin')->default(true);
        $sql = $b->compileCreate();
        $this->assertStringContainsString("DEFAULT 1", $sql[0]);
    }

    public function testDefaultStringValue(): void
    {
        $b = new Blueprint('test');
        $b->string('status')->default('pending');
        $sql = $b->compileCreate();
        $this->assertStringContainsString("DEFAULT 'pending'", $sql[0]);
    }

    public function testIdNoDuplicatePrimary(): void
    {
        $b = new Blueprint('test', 'mysql');
        $b->id();
        $sql = $b->compileCreate();
        // 'id' type column already inlines PRIMARY KEY — must not duplicate
        $this->assertStringContainsString('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', $sql[0]);
        $this->assertStringNotContainsString("PRIMARY KEY", substr($sql[0], strpos($sql[0], 'PRIMARY KEY') + 12));
    }

    public function testDropIndex(): void
    {
        $b = new Blueprint('test');
        $b->dropIndex('idx_email');
        $sql = $b->compileAlter();
        $this->assertStringContainsString('DROP INDEX `idx_email`', $sql[0]);
    }

    public function testDropForeign(): void
    {
        $b = new Blueprint('test');
        $b->dropForeign('fk_user_id');
        $sql = $b->compileAlter();
        $this->assertStringContainsString('DROP FOREIGN KEY `fk_user_id`', $sql[0]);
    }

    public function testAlterAddColumn(): void
    {
        $b = new Blueprint('test', 'mysql');
        $b->string('email');
        $sql = $b->compileAlter();
        $this->assertStringContainsString('ADD COLUMN `email` VARCHAR', $sql[0]);
    }

    public function testAlterDropColumn(): void
    {
        $b = new Blueprint('test');
        $b->dropColumn('old_field');
        $sql = $b->compileAlter();
        $this->assertStringContainsString('DROP COLUMN `old_field`', $sql[0]);
    }

    public function testAlterAddUnique(): void
    {
        $b = new Blueprint('test');
        $b->unique('email');
        $sql = $b->compileAlter();
        $this->assertStringContainsString('CREATE UNIQUE INDEX', $sql[0]);
    }

    public function testAlterAddIndex(): void
    {
        $b = new Blueprint('test');
        $b->index('status');
        $sql = $b->compileAlter();
        $this->assertStringContainsString('CREATE INDEX', $sql[0]);
    }

    // ========================================================================
    // after() modifier
    // ========================================================================

    public function testAfterInAlterMysql(): void
    {
        $b = new Blueprint('test', 'mysql');
        $b->string('email')->after('id');
        $sql = $b->compileAlter();
        $this->assertStringContainsString('AFTER `id`', $sql[0]);
    }

    public function testAfterInAlterMariadb(): void
    {
        $b = new Blueprint('test', 'mariadb');
        $b->string('email')->after('id');
        $sql = $b->compileAlter();
        $this->assertStringContainsString('AFTER `id`', $sql[0]);
    }

    public function testAfterSilentInCreate(): void
    {
        // AFTER is NOT valid MySQL syntax in CREATE TABLE — must be ignored
        $b = new Blueprint('test', 'mysql');
        $b->id();
        $b->string('name')->after('id');
        $sql = $b->compileCreate();
        $this->assertStringNotContainsString('AFTER', $sql[0]);
    }

    public function testAfterSilentInAlterSqlite(): void
    {
        // SQLite does NOT support AFTER column placement
        $b = new Blueprint('test', 'sqlite');
        $b->string('email')->after('id');
        $sql = $b->compileAlter();
        $this->assertStringNotContainsString('AFTER', $sql[0]);
    }

    public function testAfterSilentInAlterPgsql(): void
    {
        // PostgreSQL does NOT support AFTER column placement
        $b = new Blueprint('test', 'pgsql');
        $b->string('email')->after('id');
        $sql = $b->compileAlter();
        $this->assertStringNotContainsString('AFTER', $sql[0]);
    }
}
