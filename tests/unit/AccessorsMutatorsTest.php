<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;
use Siro\Core\Tests\TestCase;

/**
 * Test Accessors, Mutators, and Appends functionality
 */
final class AccessorsMutatorsTest extends TestCase
{
    /**
     * Test accessor method is called when getting attribute
     */
    public function testAccessorIsCalled(): void
    {
        $model = new class extends Model {
            protected string $table = 'test_users';
            protected array $fillable = ['name'];

            public function getNameAttribute(mixed $value): string
            {
                return ucfirst((string) $value);
            }
        };

        $model->fill(['name' => 'john doe']);
        $this->assertSame('John doe', $model->getAttribute('name'));
        $this->assertSame('John doe', $model->name);
    }

    /**
     * Test accessor with null value
     */
    public function testAccessorWithNullValue(): void
    {
        $model = new class extends Model {
            protected string $table = 'test_users';

            public function getNameAttribute(mixed $value): ?string
            {
                return $value !== null ? strtoupper((string) $value) : null;
            }
        };

        $this->assertNull($model->getAttribute('name'));
    }

    /**
     * Test appends are included in toArray()
     */
    public function testAppendsAreIncludedInToArray(): void
    {
        $model = new class extends Model {
            protected string $table = 'test_users';
            protected array $fillable = ['first_name', 'last_name'];
            protected array $appends = ['full_name'];

            public function getFullNameAttribute(): string
            {
                return ($this->first_name ?? '') . ' ' . ($this->last_name ?? '');
            }
        };

        $model->fill(['first_name' => 'John', 'last_name' => 'Doe']);
        $array = $model->toArray();

        $this->assertArrayHasKey('full_name', $array);
        $this->assertSame('John Doe', $array['full_name']);
    }

    /**
     * Test appends respect hidden attributes
     */
    public function testAppendsRespectHiddenAttributes(): void
    {
        $model = new class extends Model {
            protected string $table = 'test_users';
            protected array $fillable = ['password'];
            protected array $hidden = ['secret'];
            protected array $appends = ['secret'];

            public function getSecretAttribute(): string
            {
                return 'should_be_hidden';
            }
        };

        $array = $model->toArray();
        $this->assertArrayNotHasKey('secret', $array);
    }

    /**
     * Test datetime cast returns formatted string for JSON serialization
     */
    public function testDatetimeCastReturnsFormattedStringForJson(): void
    {
        // Create a concrete model class to avoid anonymous class issues
        $model = new TestPostModel();

        // Simulate database returning DateTime object via hydrate
        $hydrated = TestPostModel::hydrate([
            'id' => 1,
            'created_at' => new \DateTime('2024-01-15 10:30:00'),
        ]);

        $value = $hydrated->getAttribute('created_at');

        $this->assertIsString($value);
        $this->assertSame('2024-01-15 10:30:00', $value);
    }

    /**
     * Test JSON serialization includes appends and formatted dates
     */
    public function testJsonSerializationIncludesAppendsAndDates(): void
    {
        // Use hydrate to simulate DB data
        $hydrated = TestUserWithAppendsModel::hydrate([
            'id' => 1,
            'name' => 'john',
            'created_at' => new \DateTime('2024-01-15 10:30:00'),
        ]);

        $json = json_encode($hydrated);

        $this->assertNotFalse($json);
        $data = json_decode($json, true);
        $this->assertArrayHasKey('uppercase_name', $data);
        $this->assertSame('JOHN', $data['uppercase_name']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertIsString($data['created_at']);
    }

    /**
     * Test getAppends and setAppends methods
     */
    public function testGetAndSetAppends(): void
    {
        $model = new class extends Model {
            protected string $table = 'test_users';
            protected array $appends = ['field1'];
        };

        $this->assertSame(['field1'], $model->getAppends());

        $model->setAppends(['field2', 'field3']);
        $this->assertSame(['field2', 'field3'], $model->getAppends());
    }

    /**
     * Test accessor does not interfere with casting
     */
    public function testAccessorDoesNotInterfereWithCasting(): void
    {
        $model = new TestItemModel();
        $model->fill(['quantity' => '42']);
        $this->assertSame(42, $model->getAttribute('quantity'));
    }
}

// Helper classes for testing
class TestPostModel extends Model
{
    protected string $table = 'test_posts';
    protected array $fillable = ['created_at'];
    protected array $casts = [
        'created_at' => 'datetime',
    ];
}

class TestUserWithAppendsModel extends Model
{
    protected string $table = 'test_users';
    protected array $fillable = ['name'];
    protected array $appends = ['uppercase_name'];
    protected array $casts = [
        'created_at' => 'datetime',
    ];

    public function getUppercaseNameAttribute(): string
    {
        return strtoupper((string) ($this->name ?? ''));
    }
}

class TestItemModel extends Model
{
    protected string $table = 'test_items';
    protected array $fillable = ['quantity'];
    protected array $casts = [
        'quantity' => 'integer',
    ];
}
