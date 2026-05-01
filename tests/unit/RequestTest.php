<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Request;

/**
 * Request Unit Tests
 * 
 * Tests all request input methods and helpers
 */
final class RequestTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a mock request with test data
        $_GET = ['page' => '2', 'search' => 'test'];
        $_POST = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => '25',
            'active' => '1',
            'price' => '99.99',
            'items' => ['item1', 'item2'],
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        $this->request = Request::fromGlobals();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_GET = [];
        $_POST = [];
    }

    /**
     * Test int() helper
     */
    public function testIntHelperReturnsInteger(): void
    {
        $age = $this->request->int('age');
        
        $this->assertIsInt($age);
        $this->assertEquals(25, $age);
    }

    public function testIntHelperWithDefault(): void
    {
        $missing = $this->request->int('missing', 42);
        
        $this->assertIsInt($missing);
        $this->assertEquals(42, $missing);
    }

    /**
     * Test string() helper
     */
    public function testStringHelperReturnsString(): void
    {
        $name = $this->request->string('name');
        
        $this->assertIsString($name);
        $this->assertEquals('John Doe', $name);
    }

    public function testStringHelperWithDefault(): void
    {
        $missing = $this->request->string('missing', 'default');
        
        $this->assertIsString($missing);
        $this->assertEquals('default', $missing);
    }

    /**
     * Test bool() helper
     */
    public function testBoolHelperReturnsBoolean(): void
    {
        $active = $this->request->bool('active');
        
        $this->assertIsBool($active);
        $this->assertTrue($active);
    }

    public function testBoolHelperWithZero(): void
    {
        $_POST['inactive'] = '0';
        $inactive = $this->request->bool('inactive');
        
        $this->assertIsBool($inactive);
        $this->assertFalse($inactive);
    }

    /**
     * Test array() helper
     */
    public function testArrayHelperReturnsArray(): void
    {
        $items = $this->request->array('items');
        
        $this->assertIsArray($items);
        $this->assertCount(2, $items);
        $this->assertEquals(['item1', 'item2'], $items);
    }

    public function testArrayHelperWithDefault(): void
    {
        $missing = $this->request->array('missing', ['default']);
        
        $this->assertIsArray($missing);
        $this->assertEquals(['default'], $missing);
    }

    /**
     * Test float() helper
     */
    public function testFloatHelperReturnsFloat(): void
    {
        $price = $this->request->float('price');
        
        $this->assertIsFloat($price);
        $this->assertEquals(99.99, $price);
    }

    public function testFloatHelperWithDefault(): void
    {
        $missing = $this->request->float('missing', 0.0);
        
        $this->assertIsFloat($missing);
        $this->assertEquals(0.0, $missing);
    }

    /**
     * Test queryInt() helper
     */
    public function testQueryIntHelperReturnsInteger(): void
    {
        $page = $this->request->queryInt('page');
        
        $this->assertIsInt($page);
        $this->assertEquals(2, $page);
    }

    public function testQueryIntHelperWithDefault(): void
    {
        $missing = $this->request->queryInt('missing', 1);
        
        $this->assertIsInt($missing);
        $this->assertEquals(1, $missing);
    }

    /**
     * Test queryString() helper
     */
    public function testQueryStringHelperReturnsString(): void
    {
        $search = $this->request->queryString('search');
        
        $this->assertIsString($search);
        $this->assertEquals('test', $search);
    }

    public function testQueryStringHelperWithDefault(): void
    {
        $missing = $this->request->queryString('missing', 'default');
        
        $this->assertIsString($missing);
        $this->assertEquals('default', $missing);
    }

    /**
     * Test only() method
     */
    public function testOnlyReturnsSelectedFields(): void
    {
        $data = $this->request->only(['name', 'email']);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayNotHasKey('age', $data);
        $this->assertEquals('John Doe', $data['name']);
    }

    public function testOnlyWithNonExistentKeys(): void
    {
        $data = $this->request->only(['name', 'nonexistent']);
        
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayNotHasKey('nonexistent', $data);
    }

    /**
     * Test validated() method exists
     */
    public function testValidatedMethodExists(): void
    {
        $this->assertTrue(method_exists($this->request, 'validated'));
    }

    /**
     * Test file() method exists
     */
    public function testFileMethodExists(): void
    {
        $this->assertTrue(method_exists($this->request, 'file'));
    }

    /**
     * Test input() method retrieves POST data
     */
    public function testInputRetrievesPostData(): void
    {
        $name = $this->request->input('name');
        
        $this->assertEquals('John Doe', $name);
    }

    /**
     * Test input() with default value
     */
    public function testInputWithDefault(): void
    {
        $missing = $this->request->input('missing', 'default');
        
        $this->assertEquals('default', $missing);
    }

    /**
     * Test query() method retrieves GET data
     */
    public function testQueryRetrievesGetData(): void
    {
        $page = $this->request->query('page');
        
        $this->assertEquals('2', $page);
    }

    /**
     * Test all() method returns all input
     */
    public function testAllReturnsAllInput(): void
    {
        $all = $this->request->all();
        
        $this->assertIsArray($all);
        $this->assertArrayHasKey('name', $all);
        $this->assertArrayHasKey('email', $all);
    }
}
