<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Resource;

/**
 * Resource Unit Tests
 * 
 * Tests resource transformation functionality
 */
final class ResourceTest extends TestCase
{
    /**
     * Test Resource class exists
     */
    public function testResourceClassExists(): void
    {
        $this->assertTrue(class_exists(Resource::class));
    }

    /**
     * Test Resource is abstract
     */
    public function testResourceIsAbstract(): void
    {
        $reflection = new \ReflectionClass(Resource::class);
        $this->assertTrue($reflection->isAbstract());
    }

    /**
     * Test Resource has make method
     */
    public function testResourceHasMakeMethod(): void
    {
        $this->assertTrue(method_exists(Resource::class, 'make'));
    }

    /**
     * Test Resource has collection method
     */
    public function testResourceHasCollectionMethod(): void
    {
        $this->assertTrue(method_exists(Resource::class, 'collection'));
    }

    /**
     * Test Resource has collectionOf method
     */
    public function testResourceHasCollectionOfMethod(): void
    {
        $this->assertTrue(method_exists(Resource::class, 'collectionOf'));
    }

    /**
     * Test Resource has toArray method (abstract)
     */
    public function testResourceHasToArrayMethod(): void
    {
        $this->assertTrue(method_exists(Resource::class, 'toArray'));
    }

    /**
     * Test Resource constructor accepts array
     */
    public function testResourceConstructorAcceptsArray(): void
    {
        $resource = new class(['id' => 1, 'name' => 'Test']) extends Resource {
            public function toArray(): array
            {
                return $this->data;
            }
        };
        
        $this->assertInstanceOf(Resource::class, $resource);
    }

    /**
     * Test Resource make returns array
     */
    public function testResourceMakeReturnsArray(): void
    {
        $resource = new class(['id' => 1]) extends Resource {
            public function toArray(): array
            {
                return $this->data;
            }
        };
        
        $result = $resource::make(['id' => 1]);
        
        $this->assertIsArray($result);
    }

    /**
     * Test Resource collection returns array
     */
    public function testResourceCollectionReturnsArray(): void
    {
        $resource = new class(['id' => 1]) extends Resource {
            public function toArray(): array
            {
                return $this->data;
            }
        };
        
        $result = $resource::collection([['id' => 1], ['id' => 2]]);
        
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test Resource collectionOf with field selection
     */
    public function testResourceCollectionOfWithFields(): void
    {
        $resource = new class(['id' => 1, 'name' => 'Test', 'email' => 'test@example.com']) extends Resource {
            public function toArray(): array
            {
                return $this->data;
            }
        };
        
        $items = [
            ['id' => 1, 'name' => 'John', 'email' => 'john@example.com'],
            ['id' => 2, 'name' => 'Jane', 'email' => 'jane@example.com'],
        ];
        
        $result = $resource::collectionOf($items, ['id', 'name']);
        
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayNotHasKey('email', $result[0]);
    }
}
