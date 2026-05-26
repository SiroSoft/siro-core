<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Fuzz;

use Siro\Core\Tests\TestCase;
use Siro\Core\Model;

final class FuzzModelTest extends TestCase
{
    /** @dataProvider provideFillData */
    public function testFillNeverThrows(array $data): void
    {
        $model = new class extends Model {
            protected string $table = 'fuzz_models';
            protected array $fillable = ['name', 'email', 'age', 'status', 'meta', 'flag'];
        };
        try {
            $model->fill($data);
            $this->assertInstanceOf(Model::class, $model);
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('Mass assignment', $e->getMessage());
        }
    }

    /** @return iterable<string, array{array}> */
    public static function provideFillData(): iterable
    {
        $datasets = [
            [], ['name' => 'test'],
            ['name' => 'test', 'email' => 'a@b.com'],
            ['name' => 'test', 'age' => 25, 'status' => true],
            ['name' => '', 'email' => ''],
            ['name' => str_repeat('x', 1000)],
            ['name' => "\0\0\0"],
            ['name' => '<script>alert(1)</script>'],
            ['name' => 'DROP TABLE users;--'],
            ['non_existent' => 'value'],
            ['name' => null, 'email' => null],
            ['meta' => ['nested' => 'data', 'count' => 5]],
            ['flag' => true, 'name' => 12345],
        ];
        $idx = 0;
        foreach ($datasets as $data) {
            yield 'fd_' . $idx++ => [$data];
        }
    }

    /** @dataProvider provideHydrateData */
    public function testHydrateNeverThrows(array $rows): void
    {
        $model = new class extends Model {
            protected string $table = 'fuzz_models';
            protected array $fillable = ['*'];
        };
        try {
            $result = $model->hydrateAll($rows);
            $this->assertIsArray($result);
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('array', $e->getMessage());
        }
    }

    /** @return iterable<string, array{array}> */
    public static function provideHydrateData(): iterable
    {
        yield 'empty' => [[]];
        yield 'single row' => [[['id' => 1, 'name' => 'test']]];
        yield 'multiple rows' => [[['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]];
        yield 'with nulls' => [[['id' => 1, 'name' => null, 'email' => null]]];
        yield 'with special chars' => [[['id' => 1, 'name' => "\0\n\t"]]];
        yield 'large values' => [[['id' => 1, 'name' => str_repeat('x', 10000)]]];
    }

    /** @dataProvider provideAttributeAccess */
    public function testAttributeAccessNeverThrows(mixed $value): void
    {
        $model = new class extends Model {
            protected string $table = 'fuzz_models';
            protected array $fillable = ['*'];
            protected array $casts = ['val' => 'int', 'price' => 'float', 'active' => 'bool'];
        };
        $model->setAttribute('val', $value);
        $model->setAttribute('price', $value);
        $model->setAttribute('active', $value);
        $got = $model->getAttribute('val');
        $this->assertTrue(true);
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideAttributeAccess(): iterable
    {
        return [
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'zero' => [0],
            'negative' => [-1],
            'float' => [3.14],
            'string' => ['test'],
            'empty_string' => [''],
            'array' => [[1, 2, 3]],
            'nested_array' => [['key' => 'value']],
            'large_int' => [PHP_INT_MAX],
        ];
    }

    /** @dataProvider provideHiddenSerialization */
    public function testHiddenAttributesNeverThrow(array $hidden, array $data): void
    {
        $model = new class($hidden) extends Model {
            protected string $table = 'fuzz_models';
            protected array $fillable = ['*'];
            public function __construct(array $hiddenFields = [])
            {
                parent::__construct();
                $this->hidden = $hiddenFields;
            }
        };
        $model->fill($data);
        $arr = $model->toArray();
        $this->assertIsArray($arr);
    }

    /** @return iterable<string, array{array, array}> */
    public static function provideHiddenSerialization(): iterable
    {
        yield 'no hidden' => [[], ['name' => 'test', 'password' => 'secret']];
        yield 'hide password' => [['password'], ['name' => 'test', 'password' => 'secret']];
        yield 'hide all' => [['name', 'email'], ['name' => 'a', 'email' => 'b']];
        yield 'hide non-existent' => [['nope'], ['name' => 'test']];
    }
}
