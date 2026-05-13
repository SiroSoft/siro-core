<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use PHPUnit\Framework\TestCase;
use Siro\Core\Schema;
use Siro\Core\DB\Blueprint;

final class SchemaTest extends TestCase
{
    public function testHasDatabaseReturnsBool(): void
    {
        $result = Schema::hasDatabase(':memory:');
        $this->assertIsBool($result);
    }

    public function testBlueprintIdColumn(): void
    {
        $bp = new Blueprint('test');
        $bp->id();
        $columns = $bp->getColumns();

        $this->assertCount(1, $columns);
        $this->assertSame('id', $columns[0]->getName());
    }

    public function testBlueprintStringColumn(): void
    {
        $bp = new Blueprint('test');
        $bp->string('name', 100);
        $columns = $bp->getColumns();

        $this->assertCount(1, $columns);
        $this->assertSame('name', $columns[0]->getName());
    }

    public function testBlueprintIntegerColumn(): void
    {
        $bp = new Blueprint('test');
        $bp->integer('count');
        $columns = $bp->getColumns();

        $this->assertSame('count', $columns[0]->getName());
    }

    public function testBlueprintTimestamps(): void
    {
        $bp = new Blueprint('test');
        $bp->timestamps();
        $columns = $bp->getColumns();

        $this->assertCount(2, $columns);
        $this->assertSame('created_at', $columns[0]->getName());
        $this->assertSame('updated_at', $columns[1]->getName());
    }

    public function testBlueprintDecimalColumn(): void
    {
        $bp = new Blueprint('test');
        $bp->decimal('price', 10, 2);
        $columns = $bp->getColumns();

        $this->assertSame('price', $columns[0]->getName());
    }
}
