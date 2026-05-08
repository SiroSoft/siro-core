<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Collection;

final class CollectionTest extends TestCase
{
    public function testMakeAndAll(): void
    {
        $items = ['a' => 1, 'b' => 2];
        $col = Collection::make($items);
        $this->assertSame($items, $col->all());
    }

    public function testCount(): void
    {
        $col = new Collection([1, 2, 3]);
        $this->assertSame(3, $col->count());
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue((new Collection())->isEmpty());
        $this->assertFalse((new Collection([1]))->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $this->assertFalse((new Collection())->isNotEmpty());
        $this->assertTrue((new Collection([1]))->isNotEmpty());
    }

    public function testFirstReturnsFirstItem(): void
    {
        $col = new Collection([10, 20, 30]);
        $this->assertSame(10, $col->first());
    }

    public function testLast(): void
    {
        $col = new Collection([10, 20, 30]);
        $this->assertSame(30, $col->last());
    }

    public function testMap(): void
    {
        $col = new Collection([1, 2, 3]);
        $mapped = $col->map(fn($v) => $v * 2);
        $this->assertSame([2, 4, 6], $mapped->all());
    }

    public function testFilter(): void
    {
        $col = new Collection([1, 2, 3, 4, 5]);
        $filtered = $col->filter(fn($v) => $v % 2 === 0);
        $this->assertSame([1 => 2, 3 => 4], $filtered->all());
    }

    public function testReduce(): void
    {
        $col = new Collection([1, 2, 3, 4]);
        $sum = $col->reduce(fn($carry, $v) => $carry + $v, 0);
        $this->assertSame(10, $sum);
    }

    public function testEach(): void
    {
        $col = new Collection([1, 2, 3]);
        $sum = 0;
        $col->each(function ($v) use (&$sum) { $sum += $v; });
        $this->assertSame(6, $sum);
    }

    public function testPluck(): void
    {
        $col = new Collection([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);
        $names = $col->pluck('name');
        $this->assertSame(['Alice', 'Bob'], $names->all());
    }

    public function testSort(): void
    {
        $col = new Collection([3, 1, 2]);
        $sorted = $col->sort();
        $this->assertSame([1, 2, 3], array_values($sorted->all()));
    }

    public function testToJson(): void
    {
        $col = new Collection(['name' => 'test', 'value' => 42]);
        $json = $col->toJson();
        $this->assertStringContainsString('"name"', $json);
        $this->assertStringContainsString('"test"', $json);
    }

    public function testJsonSerialize(): void
    {
        $col = new Collection([1, 2, 3]);
        $this->assertSame([1, 2, 3], $col->jsonSerialize());
    }

    public function testArrayAccess(): void
    {
        $col = new Collection(['a' => 1, 'b' => 2]);
        $this->assertTrue(isset($col['a']));
        $this->assertSame(1, $col['a']);
        $col['c'] = 3;
        $this->assertSame(3, $col['c']);
        unset($col['a']);
        $this->assertFalse(isset($col['a']));
    }

    public function testGetIterator(): void
    {
        $col = new Collection([1, 2, 3]);
        $result = [];
        foreach ($col as $v) {
            $result[] = $v;
        }
        $this->assertSame([1, 2, 3], $result);
    }
}
