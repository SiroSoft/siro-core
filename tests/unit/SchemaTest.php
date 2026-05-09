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
}