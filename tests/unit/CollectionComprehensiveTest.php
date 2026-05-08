<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Collection;

final class CollectionComprehensiveTest extends TestCase
{
    public function testMakeStaticMethod(): void
    {
        $collection = Collection::make([1, 2, 3]);
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertEquals([1, 2, 3], $collection->all());
    }

    public function testAllReturnsItems(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);
        $this->assertEquals(['a' => 1, 'b' => 2], $collection->all());
    }

    public function testCount(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $this->assertEquals(5, $collection->count());
    }

    public function testIsEmptyAndIsNotEmpty(): void
    {
        $empty = new Collection([]);
        $notEmpty = new Collection([1]);

        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->isNotEmpty());
        $this->assertFalse($notEmpty->isEmpty());
        $this->assertTrue($notEmpty->isNotEmpty());
    }

    public function testFirstAndLast(): void
    {
        $collection = new Collection([10, 20, 30]);
        $this->assertEquals(10, $collection->first());
        $this->assertEquals(30, $collection->last());
    }

    public function testFirstLastEmptyCollection(): void
    {
        $collection = new Collection([]);
        $this->assertNull($collection->first());
        $this->assertNull($collection->last());
    }

    public function testGetWithKey(): void
    {
        $collection = new Collection(['name' => 'John', 'age' => 30]);
        $this->assertEquals('John', $collection->get('name'));
        $this->assertEquals(30, $collection->get('age'));
    }

    public function testGetWithDefault(): void
    {
        $collection = new Collection(['name' => 'John']);
        $this->assertEquals('default', $collection->get('missing', 'default'));
    }

    public function testGetAllWhenKeyIsNull(): void
    {
        $collection = new Collection([1, 2, 3]);
        $this->assertEquals([1, 2, 3], $collection->get(null));
    }

    public function testSetReturnsSelf(): void
    {
        $collection = new Collection([1, 2]);
        $result = $collection->set('key', 'value');
        $this->assertSame($collection, $result);
        $this->assertEquals('value', $collection->get('key'));
    }

    public function testPushReturnsSelf(): void
    {
        $collection = new Collection([1, 2]);
        $result = $collection->push(3);
        $this->assertSame($collection, $result);
        $this->assertEquals([1, 2, 3], array_values($collection->all()));
    }

    public function testPopRemovesLast(): void
    {
        $collection = new Collection([1, 2, 3]);
        $popped = $collection->pop();
        $this->assertEquals(3, $popped);
        $this->assertEquals([1, 2], array_values($collection->all()));
    }

    public function testShiftRemovesFirst(): void
    {
        $collection = new Collection([1, 2, 3]);
        $shifted = $collection->shift();
        $this->assertEquals(1, $shifted);
        $this->assertEquals([2, 3], array_values($collection->all()));
    }

    public function testUnshiftAddsToBeginning(): void
    {
        $collection = new Collection([2, 3]);
        $result = $collection->unshift(1);
        $this->assertSame($collection, $result);
        $this->assertEquals([1, 2, 3], array_values($collection->all()));
    }

    public function testPluckSimple(): void
    {
        $collection = new Collection([
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25]
        ]);
        $names = $collection->pluck('name');
        $this->assertEquals(['John', 'Jane'], array_values($names->all()));
    }

    public function testPluckWithKey(): void
    {
        $collection = new Collection([
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane']
        ]);
        $names = $collection->pluck('name', 'id');
        $this->assertEquals([1 => 'John', 2 => 'Jane'], $names->all());
    }

    public function testMapTransformsItems(): void
    {
        $collection = new Collection([1, 2, 3]);
        $doubled = $collection->map(fn($item) => $item * 2);
        $this->assertEquals([2, 4, 6], array_values($doubled->all()));
    }

    public function testMapPreservesKeys(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);
        $mapped = $collection->map(fn($item, $key) => strtoupper($key) . ':' . $item);
        $this->assertEquals(['A:1', 'B:2'], array_values($mapped->all()));
    }

    public function testFilterWithoutCallback(): void
    {
        $collection = new Collection([0, 1, false, 2, '', 3]);
        $filtered = $collection->filter();
        $this->assertEquals([1, 2, 3], array_values($filtered->all()));
    }

    public function testFilterWithCallback(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $evens = $collection->filter(fn($item) => $item % 2 === 0);
        $this->assertEquals([2, 4], array_values($evens->all()));
    }

    public function testRejectOppositeOfFilter(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $odds = $collection->reject(fn($item) => $item % 2 === 0);
        $this->assertEquals([1, 3, 5], array_values($odds->all()));
    }

    public function testReduceAccumulates(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $sum = $collection->reduce(fn($carry, $item) => $carry + $item, 0);
        $this->assertEquals(15, $sum);
    }

    public function testReduceWithoutInitial(): void
    {
        $collection = new Collection([1, 2, 3]);
        $concatenated = $collection->reduce(fn($carry, $item) => $carry . $item);
        $this->assertEquals('123', $concatenated);
    }

    public function testEachIterates(): void
    {
        $collection = new Collection([1, 2, 3]);
        $sum = 0;
        $result = $collection->each(fn($item) => $sum += $item);
        $this->assertEquals(6, $sum);
        $this->assertSame($collection, $result);
    }

    public function testWhereEquals(): void
    {
        $collection = new Collection([
            ['status' => 'active', 'name' => 'John'],
            ['status' => 'inactive', 'name' => 'Jane'],
            ['status' => 'active', 'name' => 'Bob']
        ]);
        $active = $collection->where('status', 'active');
        $this->assertCount(2, $active);
    }

    public function testWhereWithOperator(): void
    {
        $collection = new Collection([
            ['age' => 20],
            ['age' => 30],
            ['age' => 40]
        ]);
        $over25 = $collection->where('age', '>', 25);
        $this->assertCount(2, $over25);
    }

    public function testWhereIn(): void
    {
        $collection = new Collection([
            ['role' => 'admin'],
            ['role' => 'user'],
            ['role' => 'moderator']
        ]);
        $staff = $collection->whereIn('role', ['admin', 'moderator']);
        $this->assertCount(2, $staff);
    }

    public function testSortAscending(): void
    {
        $collection = new Collection([3, 1, 4, 1, 5]);
        $sorted = $collection->sort();
        $this->assertEquals([1, 1, 3, 4, 5], array_values($sorted->all()));
    }

    public function testSortByColumn(): void
    {
        $collection = new Collection([
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25]
        ]);
        $sorted = $collection->sort('age');
        $this->assertEquals(25, $sorted->first()['age']);
    }

    public function testSortByDesc(): void
    {
        $collection = new Collection([
            ['score' => 80],
            ['score' => 95],
            ['score' => 70]
        ]);
        $sorted = $collection->sortByDesc('score');
        $this->assertEquals(95, $sorted->first()['score']);
    }

    public function testReverse(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $reversed = $collection->reverse();
        $this->assertEquals([5, 4, 3, 2, 1], array_values($reversed->all()));
    }

    public function testSlice(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $sliced = $collection->slice(1, 3);
        $this->assertEquals([2, 3, 4], array_values($sliced->all()));
    }

    public function testChunk(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5, 6]);
        $chunks = $collection->chunk(2);
        $this->assertCount(3, $chunks);
        $this->assertEquals([1, 2], array_values($chunks[0]->all()));
        $this->assertEquals([3, 4], array_values($chunks[1]->all()));
    }

    public function testUniqueWithoutKey(): void
    {
        $collection = new Collection([1, 2, 2, 3, 3, 3]);
        $unique = $collection->unique();
        $this->assertEquals([1, 2, 3], array_values($unique->all()));
    }

    public function testUniqueWithKey(): void
    {
        $collection = new Collection([
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
            ['id' => 1, 'name' => 'Duplicate']
        ]);
        $unique = $collection->unique('id');
        $this->assertCount(2, $unique);
    }

    public function testCollapse(): void
    {
        $collection = new Collection([
            [1, 2, 3],
            [4, 5, 6]
        ]);
        $collapsed = $collection->collapse();
        $this->assertEquals([1, 2, 3, 4, 5, 6], array_values($collapsed->all()));
    }

    public function testFlattenDepth1(): void
    {
        $collection = new Collection([1, [2, 3], [4, [5, 6]]]);
        $flattened = $collection->flatten(1);
        $this->assertEquals([1, 2, 3, 4, [5, 6]], array_values($flattened->all()));
    }

    public function testFlattenFully(): void
    {
        $collection = new Collection([1, [2, [3, [4]]]]);
        $flattened = $collection->flatten();
        $this->assertEquals([1, 2, 3, 4], array_values($flattened->all()));
    }

    public function testCombine(): void
    {
        $keys = new Collection(['a', 'b', 'c']);
        $values = [1, 2, 3];
        $combined = $keys->combine($values);
        $this->assertEquals(['a' => 1, 'b' => 2, 'c' => 3], $combined->all());
    }

    public function testKeys(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
        $keys = $collection->keys();
        $this->assertEquals(['a', 'b', 'c'], array_values($keys->all()));
    }

    public function testValues(): void
    {
        $collection = new Collection([1 => 'a', 3 => 'b', 5 => 'c']);
        $values = $collection->values();
        $this->assertEquals([0 => 'a', 1 => 'b', 2 => 'c'], $values->all());
    }

    public function testMergeArray(): void
    {
        $collection = new Collection([1, 2]);
        $merged = $collection->merge([3, 4]);
        $this->assertEquals([1, 2, 3, 4], array_values($merged->all()));
    }

    public function testMergeCollection(): void
    {
        $collection1 = new Collection([1, 2]);
        $collection2 = new Collection([3, 4]);
        $merged = $collection1->merge($collection2);
        $this->assertEquals([1, 2, 3, 4], array_values($merged->all()));
    }

    public function testToJson(): void
    {
        $collection = new Collection(['name' => 'John', 'age' => 30]);
        $json = $collection->toJson();
        $this->assertJsonStringEqualsJsonString(
            json_encode(['name' => 'John', 'age' => 30]),
            $json
        );
    }

    public function testJsonSerialize(): void
    {
        $collection = new Collection([1, 2, 3]);
        $this->assertEquals([1, 2, 3], $collection->jsonSerialize());
    }

    public function testToArray(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);
        $this->assertEquals(['a' => 1, 'b' => 2], $collection->toArray());
    }

    public function testImplode(): void
    {
        $collection = new Collection(['a', 'b', 'c']);
        $this->assertEquals('a,b,c', $collection->implode(','));
        $this->assertEquals('a-b-c', $collection->implode('-'));
    }

    public function testSum(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $this->assertEquals(15, $collection->sum());
    }

    public function testSumWithColumn(): void
    {
        $collection = new Collection([
            ['price' => 10],
            ['price' => 20],
            ['price' => 30]
        ]);
        $this->assertEquals(60, $collection->sum('price'));
    }

    public function testAvg(): void
    {
        $collection = new Collection([10, 20, 30, 40, 50]);
        $this->assertEquals(30, $collection->avg());
    }

    public function testAvgEmpty(): void
    {
        $collection = new Collection([]);
        $this->assertEquals(0, $collection->avg());
    }

    public function testMin(): void
    {
        $collection = new Collection([5, 2, 8, 1, 9]);
        $this->assertEquals(1, $collection->min());
    }

    public function testMax(): void
    {
        $collection = new Collection([5, 2, 8, 1, 9]);
        $this->assertEquals(9, $collection->max());
    }

    public function testShuffle(): void
    {
        $collection = new Collection(range(1, 100));
        $shuffled = $collection->shuffle();
        $this->assertCount(100, $shuffled);
        $this->assertEquals(range(1, 100), $shuffled->sort()->values()->all());
    }

    public function testRandomSingle(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $random = $collection->random();
        $this->assertContains($random, [1, 2, 3, 4, 5]);
    }

    public function testRandomMultiple(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $random = $collection->random(3);
        $this->assertInstanceOf(Collection::class, $random);
        $this->assertCount(3, $random);
    }

    public function testTap(): void
    {
        $collection = new Collection([1, 2, 3]);
        $sideEffect = null;
        $result = $collection->tap(fn($col) => $sideEffect = $col->sum());
        $this->assertEquals(6, $sideEffect);
        $this->assertSame($collection, $result);
    }

    public function testPipe(): void
    {
        $collection = new Collection([1, 2, 3]);
        $result = $collection->pipe(fn($col) => $col->sum() * 2);
        $this->assertEquals(12, $result);
    }

    public function testArrayAccessOffsetExists(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);
        $this->assertTrue(isset($collection['a']));
        $this->assertFalse(isset($collection['c']));
    }

    public function testArrayAccessOffsetGet(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);
        $this->assertEquals(1, $collection['a']);
        $this->assertNull($collection['c']);
    }

    public function testArrayAccessOffsetSet(): void
    {
        $collection = new Collection([1, 2]);
        $collection['a'] = 3;
        $this->assertEquals(3, $collection['a']);
    }

    public function testArrayAccessOffsetUnset(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2]);
        unset($collection['a']);
        $this->assertFalse(isset($collection['a']));
    }

    public function testIteratorAggregate(): void
    {
        $collection = new Collection([1, 2, 3]);
        $items = [];
        foreach ($collection as $key => $value) {
            $items[$key] = $value;
        }
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $items);
    }

    public function testCountableInterface(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $this->assertEquals(5, count($collection));
    }

    public function testLargeCollectionPerformance(): void
    {
        $size = 10000;
        $collection = new Collection(range(1, $size));

        $start = microtime(true);
        $result = $collection
            ->filter(fn($x) => $x % 2 === 0)
            ->map(fn($x) => $x * 2)
            ->sum();
        $elapsed = microtime(true) - $start;

        $expectedSum = array_sum(array_map(fn($x) => $x * 2, range(2, $size, 2)));
        $this->assertEquals($expectedSum, $result);
        $this->assertLessThan(2.0, $elapsed, "Processing took {$elapsed}s");
    }

    public function testChainedOperations(): void
    {
        $collection = new Collection(range(1, 100));
        $result = $collection
            ->filter(fn($x) => $x % 2 === 0)
            ->map(fn($x) => $x * $x)
            ->sort()
            ->slice(0, 5)
            ->sum();

        $this->assertGreaterThan(0, $result);
    }

    public function testNestedCollections(): void
    {
        $nested = new Collection([
            new Collection([1, 2]),
            new Collection([3, 4])
        ]);
        $this->assertInstanceOf(Collection::class, $nested->first());
    }

    public function testMemoryEfficiency(): void
    {
        $memoryBefore = memory_get_usage(true);
        $collection = new Collection(range(1, 100000));
        $sum = $collection->sum();
        $memoryAfter = memory_get_usage(true);

        $this->assertGreaterThan(0, $sum);
        $this->assertLessThan(50 * 1024 * 1024, $memoryAfter - $memoryBefore);
    }
}
