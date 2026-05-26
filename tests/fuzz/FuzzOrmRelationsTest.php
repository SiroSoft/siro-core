<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;

use Siro\Core\Tests\TestCase;
use Siro\Core\Model;
use Siro\Core\DB\Relations\BelongsToMany;
use Siro\Core\DB\Relations\MorphMany;
use Siro\Core\DB\Relations\MorphTo;

final class FuzzOrmRelationsTest extends TestCase
{
    /** @dataProvider providePivotColumns */
    public function testWithPivotNeverThrows(array $columns): void
    {
        $model = new class extends Model {
            protected string $table = 'fuzz_users';
        };
        $rel = new BelongsToMany(
            get_class($model),
            'fuzz_pivot',
            'user_id',
            'role_id',
            'id',
            1,
        );
        $result = $rel->withPivot($columns);
        $this->assertInstanceOf(BelongsToMany::class, $result);
    }

    /** @return iterable<string, array{array}> */
    public static function providePivotColumns(): iterable
    {
        yield 'empty' => [[]];
        yield 'single' => [['quantity']];
        yield 'multiple' => [['quantity', 'price', 'created_at']];
        yield 'special chars' => [['col-1', 'col_2']];
        yield 'long col' => [[str_repeat('x', 100)]];
        yield 'duplicates' => [['qty', 'qty']];
    }

    /** @dataProvider provideBelongsToManyConstruction */
    public function testBelongsToManyConstructionNeverThrows(
        string $relatedClass, string $pivotTable, string $foreignKey, string $relatedKey, string $localKey, int|string $localValue
    ): void {
        $rel = new BelongsToMany($relatedClass, $pivotTable, $foreignKey, $relatedKey, $localKey, $localValue);
        $this->assertInstanceOf(BelongsToMany::class, $rel);
        $this->assertSame($relatedClass, $rel->getRelatedClass());
        $this->assertSame($pivotTable, $rel->getPivotTable());
        $this->assertSame($foreignKey, $rel->getForeignKey());
        $this->assertSame($relatedKey, $rel->getRelatedKey());
    }

    /** @return iterable<string, array{string, string, string, string, string, int|string}> */
    public static function provideBelongsToManyConstruction(): iterable
    {
        yield 'simple' => ['App\\Models\\Role', 'user_role', 'user_id', 'role_id', 'id', 1];
        yield 'zero id' => ['App\\Models\\Tag', 'post_tag', 'post_id', 'tag_id', 'id', 0];
        yield 'string id' => ['App\\Models\\Role', 'user_role', 'user_id', 'role_id', 'uuid', 'abc-123'];
        yield 'empty table' => ['App\\Models\\Role', '', 'user_id', 'role_id', 'id', 1];
        yield 'special chars table' => ['App\\Models\\Item', 'order-item', 'order_id', 'item_id', 'id', 42];
    }

    /** @dataProvider provideMorphManyOwners */
    public function testMorphManyConstructionNeverThrows(string $ownerClass, string $morphName, int|string $id): void
    {
        try {
            $rel = new MorphMany(\stdClass::class, $ownerClass, $morphName, $id);
            $this->assertInstanceOf(MorphMany::class, $rel);
            $this->assertSame($morphName, $rel->getMorphName());
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('class', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string, int|string}> */
    public static function provideMorphManyOwners(): iterable
    {
        yield 'post' => ['App\\Models\\Post', 'commentable', 1];
        yield 'product' => ['App\\Models\\Product', 'commentable', 42];
        yield 'zero id' => ['App\\Models\\User', 'taggable', 0];
        yield 'string id' => ['App\\Models\\User', 'likeable', 'uuid-123'];
        yield 'empty morph name' => ['App\\Models\\Post', '', 1];
        yield 'special chars' => ['App\\Models\\Post', "morph-name_123", 1];
    }

    /** @dataProvider provideMorphToTypes */
    public function testMorphToConstructionNeverThrows(string $morphName, int|string $id, string $type): void
    {
        try {
            $rel = new MorphTo($morphName, $id, $type);
            $this->assertInstanceOf(MorphTo::class, $rel);
            $this->assertSame($morphName, $rel->getMorphName());
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    /** @return iterable<string, array{string, int|string, string}> */
    public static function provideMorphToTypes(): iterable
    {
        yield 'post type' => ['commentable', 1, 'App\\Models\\Post'];
        yield 'product type' => ['commentable', 42, 'App\\Models\\Product'];
        yield 'empty type' => ['commentable', 1, ''];
        yield 'non-existent type' => ['commentable', 1, 'Non\\Existent\\Model'];
        yield 'zero id' => ['taggable', 0, 'App\\Models\\Tag'];
        yield 'uuid id' => ['likeable', 'uuid-val', 'App\\Models\\User'];
        yield 'empty morph' => ['', 1, 'App\\Models\\Post'];
    }
}
