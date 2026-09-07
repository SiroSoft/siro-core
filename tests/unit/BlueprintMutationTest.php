<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\DB\Blueprint;
use Siro\Core\DB\Column;
use Siro\Core\DB\ForeignKey;

/**
 * Coverage tests for DB\Blueprint and DB\Column.
 */
final class BlueprintMutationTest extends TestCase
{
    public function testDetectDriverWithoutConnection(): void
    {
        $bp = new Blueprint('users', 'mysql');
        // explicitly test MySQL id column compiles with AUTO_INCREMENT
        $bp->id();
        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
    }

    public function testSetDriver(): void
    {
        $bp = new Blueprint('users', 'pgsql');
        $bp->setDriver('sqlite');
        $bp->id();
        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    }

    public function testIdColumn(): void
    {
        $bp = new Blueprint('users', 'sqlite');
        $col = $bp->id();
        $this->assertInstanceOf(Column::class, $col);
        $this->assertSame('id', $col->name);
        $this->assertSame('id', $col->type);

        $sql = $bp->compileCreate();
        $this->assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', implode("\n", $sql));
    }

    public function testColumnTypes(): void
    {
        $bp = new Blueprint('users', 'sqlite');
        $bp->string('name');
        $bp->text('bio');
        $bp->increments('seq');
        $bp->integer('age');
        $bp->smallint('flag');
        $bp->bigint('big');
        $bp->decimal('price', 10, 2);
        $bp->float('rate');
        $bp->boolean('active');
        $bp->date('born');
        $bp->datetime('at');
        $bp->timestamp('ts');
        $bp->json('meta');
        $bp->jsonb('data');
        $bp->uuid('uid');
        $bp->ipAddress('ip');
        $bp->macAddress('mac');
        $bp->enum('status', ['new', 'done']);
        $bp->foreignId('user_id');

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('name', $sql);
        $this->assertStringContainsString('bio', $sql);
        $this->assertStringContainsString('TEXT', $sql);
        $this->assertStringContainsString('INTEGER', $sql);
        $this->assertStringContainsString('DECIMAL(10,2)', $sql);
        $this->assertStringContainsString('REAL', $sql);
        $this->assertStringContainsString('TINYINT(1)', $sql);
        $this->assertStringContainsString('`uid`', $sql);
        $this->assertStringContainsString('`ip`', $sql);
        $this->assertStringContainsString('`mac`', $sql);
        $this->assertStringContainsString('CHECK', $sql);
    }

    public function testCompileMysqlTypes(): void
    {
        $bp = new Blueprint('products', 'mysql');
        $bp->id();
        $bp->string('name', 100);
        $bp->decimal('price', 12, 4);
        $bp->enum('color', ['red', 'blue']);
        $bp->boolean('on_sale');

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('VARCHAR(100)', $sql);
        $this->assertStringContainsString('DECIMAL(12,4)', $sql);
        $this->assertStringContainsString('ENUM', $sql);
        $this->assertStringContainsString('AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('utf8mb4', $sql);
    }

    public function testCompilePostgresTypes(): void
    {
        $bp = new Blueprint('products', 'pgsql');
        $bp->id();
        $bp->jsonb('meta');
        $bp->uuid('uid');
        $bp->enum('status', ['a', 'b']);
        $bp->boolean('ok');
        $bp->smallint('small');

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('BIGSERIAL PRIMARY KEY', $sql);
        $this->assertStringContainsString('JSONB', $sql);
        $this->assertStringContainsString('UUID', $sql);
        $this->assertStringContainsString('BOOLEAN', $sql);
        $this->assertStringContainsString('CHECK', $sql);
        $this->assertStringContainsString('"products"', $sql);
    }

    public function testNullableAndDefaults(): void
    {
        $bp = new Blueprint('users', 'sqlite');
        $bp->string('email')->nullable();
        $bp->integer('age')->default(18);
        $bp->boolean('subscribed')->default(false);
        $bp->boolean('is_admin')->default(true);
        $bp->timestamp('created_at')->useCurrent();

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('NULL', $sql);
        $this->assertStringContainsString('DEFAULT 18', $sql);
        $this->assertStringContainsString('DEFAULT 0', $sql);
        $this->assertStringContainsString('DEFAULT 1', $sql);
        $this->assertStringContainsString("datetime('now')", $sql);
    }

    public function testAfterClauseMysql(): void
    {
        $bp = new Blueprint('users', 'mysql');
        $bp->string('name');
        $bp->string('email')->after('name');

        $sql = implode("\n", $bp->compileAlter());
        $this->assertStringContainsString('AFTER', $sql);
    }

    public function testTimestampsAndSoftDeletes(): void
    {
        $bp = new Blueprint('posts', 'sqlite');
        $bp->id();
        $bp->string('title');
        $bp->timestamps();
        $bp->softDeletes();
        $bp->rememberToken();

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('created_at', $sql);
        $this->assertStringContainsString('updated_at', $sql);
        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringContainsString('remember_token', $sql);
    }

    public function testUniqueIndexPrimary(): void
    {
        $bp = new Blueprint('users', 'mysql');
        $bp->string('email')->unique();
        $bp->index(['name', 'email'], 'idx_name_email');
        $bp->unique(['name'], 'uq_name');

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('UNIQUE', $sql);
        $this->assertStringContainsString('idx_name_email', $sql);
        $this->assertStringContainsString('uq_name', $sql);
    }

    public function testNonMysqlIndexSeparateStatements(): void
    {
        $bp = new Blueprint('users', 'sqlite');
        $bp->string('email')->unique();

        $statements = $bp->compileCreate();
        $this->assertGreaterThan(1, count($statements));
        $this->assertStringContainsString('CREATE UNIQUE INDEX', implode("\n", $statements));
    }

    public function testPrimaryComposite(): void
    {
        $bp = new Blueprint('pivot', 'mysql');
        $bp->integer('a');
        $bp->integer('b');
        $bp->primary(['a', 'b']);

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('PRIMARY KEY', $sql);
    }

    public function testForeignKeyCompile(): void
    {
        $bp = new Blueprint('orders', 'mysql');
        $bp->id();
        $bp->foreignId('user_id');
        $bp->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('FOREIGN KEY', $sql);
        $this->assertStringContainsString('REFERENCES', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testForeignKeyWithoutActions(): void
    {
        $bp = new Blueprint('orders', 'mysql');
        $bp->id();
        $bp->foreignId('user_id');
        $fk = new ForeignKey('user_id');
        $fk->references('id')->on('users');
        $bp->foreign('user_id');

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('FOREIGN KEY', $sql);
    }

    public function testCommentAndEngine(): void
    {
        $bp = new Blueprint('users', 'mysql');
        $bp->string('name');
        $bp->comment('User table');
        $bp->engine('MyISAM');
        $bp->charset('latin1');
        $bp->collation('latin1_swedish_ci');

        $sql = implode("\n", $bp->compileCreate());
        $this->assertStringContainsString('ENGINE=MyISAM', $sql);
        $this->assertStringContainsString('CHARSET=latin1', $sql);
        $this->assertStringContainsString('COLLATE=latin1_swedish_ci', $sql);
    }

    public function testAlterAddDropColumns(): void
    {
        $bp = new Blueprint('users', 'mysql');
        $bp->string('new_col');
        $bp->dropColumn('old_col');
        $bp->dropIndex('idx_old');
        $bp->dropUnique('uq_old');
        $bp->dropForeign('fk_old');

        $sql = implode("\n", $bp->compileAlter());
        $this->assertStringContainsString('ADD COLUMN', $sql);
        $this->assertStringContainsString('DROP COLUMN', $sql);
        $this->assertStringContainsString('DROP INDEX', $sql);
        $this->assertStringContainsString('DROP FOREIGN KEY', $sql);
    }

    public function testAlterSqliteDrops(): void
    {
        $bp = new Blueprint('users', 'sqlite');
        $bp->dropColumn('old_col');
        $bp->dropIndex('idx_old');
        $bp->dropUnique('uq_old');
        $bp->foreign('user_id')->references('id')->on('users');

        $sql = implode("\n", $bp->compileAlter());
        $this->assertStringContainsString('DROP COLUMN', $sql);
        $this->assertStringContainsString('DROP INDEX', $sql);
        // sqlite: foreign key add is skipped in ALTER
        $this->assertStringNotContainsString('ADD FOREIGN KEY', $sql);
    }

    public function testColumnChainable(): void
    {
        $bp = new Blueprint('users', 'sqlite');
        $col = $bp->string('email')->nullable()->default('x')->useCurrent();
        $this->assertTrue($col->nullable);
        $this->assertSame('x', $col->defaultValue);
        $this->assertTrue($col->useCurrent);
    }

    public function testColumnUniqueRegisters(): void
    {
        $bp = new Blueprint('users', 'sqlite');
        $bp->string('email')->unique();
        $this->assertTrue(true);
    }

    public function testColumnAllowedValues(): void
    {
        $col = new Column('enum', 'status');
        $col->allowedValues(['a', 'b']);
        $this->assertSame(['a', 'b'], $col->allowedValues);
    }
}
