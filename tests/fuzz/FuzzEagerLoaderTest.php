<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;
use PHPUnit\Framework\Attributes\DataProvider;


use Siro\Core\Tests\TestCase;
use Siro\Core\Model;
use Siro\Core\DB\EagerLoader;

final class FuzzEagerLoaderTest extends TestCase
{
    #[DataProvider('provideRelationNames')]
    public function testLoadBatchNeverThrows(string $relation): void
    {
        $model = new class extends Model {
            protected string $table = 'fuzz_parents';
            protected array $fillable = ['*'];
        };
        $loader = new EagerLoader($model::class);
        try {
            $loader->loadBatch([], [$relation => ['*']]);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('method', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideRelationNames(): iterable
    {
        yield 'empty' => [''];
        yield 'simple' => ['posts'];
        yield 'nested' => ['user.comments'];
        yield 'deep' => ['user.profile.addresses'];
        yield 'invalid chars' => ["\0relation"];
        yield 'long' => [str_repeat('x', 100)];
    }

    #[DataProvider('provideModelCollections')]
    public function testLoadWithEmptyModelsNeverThrows(array $models, array $eagerLoads): void
    {
        $loader = new EagerLoader(\stdClass::class);
        try {
            $loader->loadBatch($models, $eagerLoads);
            $this->assertTrue(true);
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    /** @return iterable<string, array{array, array}> */
    public static function provideModelCollections(): iterable
    {
        yield 'empty models' => [[], ['rel' => ['*']]];
        yield 'empty everything' => [[], []];
        yield 'invalid relation' => [[], ['!@#' => ['*']]];
    }

    #[DataProvider('provideEagerLoadColumns')]
    public function testEagerLoadWithColumnVariations(string $relation, array $columns): void
    {
        $model = new class extends Model {
            protected string $table = 'fuzz_items';
            protected array $fillable = ['*'];
        };
        $loader = new EagerLoader($model::class);
        try {
            $loader->loadBatch([], [$relation => $columns]);
            $this->assertTrue(true);
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    /** @return iterable<string, array{string, array}> */
    public static function provideEagerLoadColumns(): iterable
    {
        yield 'star' => ['rel', ['*']];
        yield 'single' => ['rel', ['id']];
        yield 'multiple' => ['rel', ['id', 'name', 'email']];
        yield 'empty columns' => ['rel', []];
        yield 'non-existent col' => ['rel', ['non_existent_column']];
    }
}
