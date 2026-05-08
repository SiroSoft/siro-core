<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Model;

final class MassAssignmentTest extends TestCase
{
    private Model $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new class(['name' => 'Test', 'email' => 'test@test.com', 'password' => 'secret']) extends Model {
            protected array $fillable = ['name', 'email'];
        };
    }

    public function testFillableFieldsAreSet(): void
    {
        $this->assertSame('Test', $this->model->getAttribute('name'));
        $this->assertSame('test@test.com', $this->model->getAttribute('email'));
    }

    public function testNonFillableFieldsAreIgnored(): void
    {
        $this->assertNull($this->model->getAttribute('password'));
    }

    public function testFillWithEmptyArray(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['name'];
        };
        $model->fill([]);
        $this->assertNull($model->getAttribute('name'));
    }

    public function testFillAfterConstruction(): void
    {
        $this->model->fill(['name' => 'Updated']);
        $this->assertSame('Updated', $this->model->getAttribute('name'));
    }

    public function testSetFillableAllowsNewFields(): void
    {
        $this->model->setFillable(['name', 'email', 'password']);
        $this->model->fill(['password' => 'new-secret']);
        $this->assertSame('new-secret', $this->model->getAttribute('password'));
    }

    public function testEmptyFillableBlocksAll(): void
    {
        $model = new class extends Model {
            protected array $fillable = [];
        };
        $model->fill(['any_field' => 'value']);
        $this->assertNull($model->getAttribute('any_field'));
    }

    public function testCastsAreApplied(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['count'];
            protected array $casts = ['count' => 'int'];
        };
        $model->fill(['count' => '42']);
        $this->assertIsInt($model->getAttribute('count'));
        $this->assertSame(42, $model->getAttribute('count'));
    }

    public function testCastsBoolean(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['active'];
            protected array $casts = ['active' => 'bool'];
        };
        $model->fill(['active' => '1']);
        $this->assertIsBool($model->getAttribute('active'));
        $this->assertTrue($model->getAttribute('active'));
    }

    public function testCastsFloat(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['price'];
            protected array $casts = ['price' => 'float'];
        };
        $model->fill(['price' => '99.99']);
        $this->assertIsFloat($model->getAttribute('price'));
        $this->assertSame(99.99, $model->getAttribute('price'));
    }

    public function testHiddenFieldsExcludedFromToArray(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['name', 'password'];
            protected array $hidden = ['password'];
        };
        $model->fill(['name' => 'Test', 'password' => 'secret']);
        $array = $model->toArray();
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayNotHasKey('password', $array);
    }

    public function testHiddenFieldsStillAccessibleDirectly(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['name', 'password'];
            protected array $hidden = ['password'];
        };
        $model->fill(['name' => 'Test', 'password' => 'secret']);
        $this->assertSame('secret', $model->getAttribute('password'));
    }

    public function testSetHiddenChangesHiddenFields(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['name', 'secret'];
            protected array $hidden = ['secret'];
        };
        $model->fill(['name' => 'Test', 'secret' => 'hidden-value']);
        $model->setHidden(['name']);
        $array = $model->toArray();
        $this->assertArrayNotHasKey('name', $array);
        $this->assertArrayHasKey('secret', $array);
    }

    public function testArrayAccessWorks(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['name'];
        };
        $model->fill(['name' => 'Test']);
        $this->assertSame('Test', $model['name']);
    }

    public function testArrayAccessSetWorks(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['name'];
        };
        $model['name'] = 'Updated';
        $this->assertSame('Updated', $model['name']);
    }

    public function testArrayAccessUnsetWorks(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['name', 'email'];
        };
        $model->fill(['name' => 'Test', 'email' => 'test@test.com']);
        unset($model['name']);
        $this->assertNull($model['name']);
        $this->assertSame('test@test.com', $model['email']);
    }

    public function testFillPreservesExistingAttributes(): void
    {
        $model = new class extends Model {
            protected array $fillable = ['name', 'email'];
        };
        $model->fill(['name' => 'First']);
        $this->assertSame('First', $model->getAttribute('name'));
        $model->fill(['email' => 'second@test.com']);
        $this->assertSame('First', $model->getAttribute('name'));
        $this->assertSame('second@test.com', $model->getAttribute('email'));
    }
}
