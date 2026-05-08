<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Request;

final class RequestTypedInputTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_POST = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/test',
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_LENGTH' => '0',
        ];
        $this->request = new Request('POST', '/test', [], [], [
            'name' => '  John Doe  ',
            'age' => '25',
            'active' => 'true',
            'price' => '99.99',
            'score' => '42',
            'tags' => ['php', 'javascript'],
            'config' => ['debug' => true],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_GET = [];
        $_POST = [];
    }

    public function testIntWithNumericString(): void
    {
        $result = $this->request->int('age');
        $this->assertSame(25, $result);
    }

    public function testIntWithMissingKey(): void
    {
        $result = $this->request->int('missing', 99);
        $this->assertSame(99, $result);
    }

    public function testIntWithFloatValue(): void
    {
        $result = $this->request->int('price');
        $this->assertSame(99, $result);
    }

    public function testStringTrimsWhitespace(): void
    {
        $result = $this->request->string('name');
        $this->assertSame('John Doe', $result);
    }

    public function testStringWithMissingKey(): void
    {
        $result = $this->request->string('missing', 'default');
        $this->assertSame('default', $result);
    }

    public function testStringWithEmptyDefault(): void
    {
        $result = $this->request->string('missing');
        $this->assertSame('', $result);
    }

    public function testBoolWithTrueString(): void
    {
        $result = $this->request->bool('active');
        $this->assertTrue($result);
    }

    public function testBoolWithYesString(): void
    {
        $_POST['flag'] = 'yes';
        $request = new Request('POST', '/test', [], [], ['flag' => 'yes']);
        $this->assertTrue($request->bool('flag'));
    }

    public function testBoolWithOnString(): void
    {
        $_POST['flag'] = 'on';
        $request = new Request('POST', '/test', [], [], ['flag' => 'on']);
        $this->assertTrue($request->bool('flag'));
    }

    public function testBoolWithFalseString(): void
    {
        $_POST['flag'] = 'false';
        $request = new Request('POST', '/test', [], [], ['flag' => 'false']);
        $this->assertFalse($request->bool('flag'));
    }

    public function testBoolWithZero(): void
    {
        $_POST['flag'] = '0';
        $request = new Request('POST', '/test', [], [], ['flag' => '0']);
        $this->assertFalse($request->bool('flag'));
    }

    public function testBoolWithMissingKey(): void
    {
        $result = $this->request->bool('missing', true);
        $this->assertTrue($result);
    }

    public function testArrayReturnsArray(): void
    {
        $result = $this->request->array('tags');
        $this->assertSame(['php', 'javascript'], $result);
    }

    public function testArrayWithNested(): void
    {
        $result = $this->request->array('config');
        $this->assertSame(['debug' => true], $result);
    }

    public function testArrayWithMissingKey(): void
    {
        $result = $this->request->array('missing', ['default']);
        $this->assertSame(['default'], $result);
    }

    public function testArrayWithEmptyDefault(): void
    {
        $result = $this->request->array('missing');
        $this->assertSame([], $result);
    }

    public function testFloatWithDecimal(): void
    {
        $result = $this->request->float('price');
        $this->assertSame(99.99, $result);
    }

    public function testFloatWithInteger(): void
    {
        $result = $this->request->float('age');
        $this->assertSame(25.0, $result);
    }

    public function testFloatWithMissingKey(): void
    {
        $result = $this->request->float('missing', 1.5);
        $this->assertSame(1.5, $result);
    }

    public function testQueryIntFromGetParams(): void
    {
        $request = new Request('GET', '/test', ['page' => '5'], [], []);
        $result = $request->queryInt('page');
        $this->assertSame(5, $result);
    }

    public function testQueryStringFromGetParams(): void
    {
        $request = new Request('GET', '/test', ['search' => 'test query'], [], []);
        $result = $request->queryString('search');
        $this->assertSame('test query', $result);
    }

    public function testQueryStringWithDefault(): void
    {
        $request = new Request('GET', '/test', [], [], []);
        $result = $request->queryString('missing', 'default');
        $this->assertSame('default', $result);
    }

    public function testIntCastsNonNumeric(): void
    {
        $_POST['value'] = 'abc';
        $request = new Request('POST', '/test', [], [], ['value' => 'abc']);
        $result = $request->int('value');
        $this->assertSame(0, $result);
    }

    public function testFloatCastsNonNumeric(): void
    {
        $_POST['value'] = 'abc';
        $request = new Request('POST', '/test', [], [], ['value' => 'abc']);
        $result = $request->float('value');
        $this->assertSame(0.0, $result);
    }

    public function testBoolCastsNonBool(): void
    {
        $_POST['value'] = 'maybe';
        $request = new Request('POST', '/test', [], [], ['value' => 'maybe']);
        $result = $request->bool('value');
        $this->assertFalse($result);
    }

    public function testIntCastsNullToZero(): void
    {
        $_POST['value'] = null;
        $request = new Request('POST', '/test', [], [], ['value' => null]);
        $result = $request->int('value', 42);
        $this->assertSame(0, $result);
    }

    public function testBoolCastsNullToFalse(): void
    {
        $_POST['value'] = null;
        $request = new Request('POST', '/test', [], [], ['value' => null]);
        $result = $request->bool('value', true);
        $this->assertFalse($result);
    }

    public function testFloatCastsNullToZero(): void
    {
        $_POST['value'] = null;
        $request = new Request('POST', '/test', [], [], ['value' => null]);
        $result = $request->float('value', 3.14);
        $this->assertSame(0.0, $result);
    }

    public function testIntWithNonExistentKeyUsesDefault(): void
    {
        $result = $this->request->int('nonexistent', 42);
        $this->assertSame(42, $result);
    }

    public function testBoolWithNonExistentKeyUsesDefault(): void
    {
        $result = $this->request->bool('nonexistent', true);
        $this->assertTrue($result);
    }

    public function testFloatWithNonExistentKeyUsesDefault(): void
    {
        $result = $this->request->float('nonexistent', 3.14);
        $this->assertSame(3.14, $result);
    }

    public function testStringWithNonExistentKeyUsesDefault(): void
    {
        $result = $this->request->string('nonexistent', 'default');
        $this->assertSame('default', $result);
    }

    public function testArrayWithNonExistentKeyUsesDefault(): void
    {
        $result = $this->request->array('nonexistent', ['default']);
        $this->assertSame(['default'], $result);
    }

    public function testPriorityPostOverQuery(): void
    {
        $request = new Request('POST', '/test', ['page' => '1'], [], ['page' => '2']);
        $result = $request->int('page');
        $this->assertSame(2, $result);
    }

    public function testBodyDataOverridesRouteParams(): void
    {
        $request = new Request('POST', '/test/:id', [], [], ['key' => 'body_val']);
        $request->setParams(['key' => 'route_val']);
        $this->assertSame('body_val', $request->input('key'));
    }

    public function testRouteParamsOverridesQuery(): void
    {
        $request = new Request('GET', '/test', ['key' => 'query_val'], [], []);
        $request->setParams(['key' => 'route_val']);
        $this->assertSame('route_val', $request->input('key'));
    }

    public function testInputSearchesBodyThenRouteThenQuery(): void
    {
        $request = new Request('POST', '/test', ['key' => 'query_val'], [], ['key' => 'body_val']);
        $request->setParams(['key' => 'route_val']);
        $this->assertSame('body_val', $request->input('key'));
    }
}
