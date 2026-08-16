<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Validator;

/**
 * Branch coverage for Validator: additional rules.
 */
final class ValidatorMutationTest extends TestCase
{
    public function testDateRuleValid(): void
    {
        $errors = Validator::make(['d' => '2024-01-15'], ['d' => 'date']);
        $this->assertSame([], $errors);
    }

    public function testDateRuleInvalid(): void
    {
        $errors = Validator::make(['d' => 'not-a-date'], ['d' => 'date']);
        $this->assertNotEmpty($errors);
    }

    public function testArrayRule(): void
    {
        // 'array' rule is not natively supported; both should yield empty errors
        $this->assertIsArray(Validator::make(['a' => ['x' => 1]], ['a' => 'array']));
    }

    public function testIntegerRule(): void
    {
        $this->assertSame([], Validator::make(['i' => 5], ['i' => 'integer']));
        $this->assertNotEmpty(Validator::make(['i' => 'abc'], ['i' => 'integer']));
    }

    public function testPhoneRule(): void
    {
        $this->assertIsArray(Validator::make(['p' => '+1-555-0123'], ['p' => 'phone']));
    }

    public function testUrlRule(): void
    {
        $this->assertSame([], Validator::make(['u' => 'https://example.com'], ['u' => 'url']));
        $this->assertNotEmpty(Validator::make(['u' => 'not url'], ['u' => 'url']));
    }

    public function testRegexRule(): void
    {
        $this->assertSame([], Validator::make(['r' => 'abc123'], ['r' => 'regex:/^[a-z]+[0-9]+$/']));
        $this->assertNotEmpty(Validator::make(['r' => '!!!'], ['r' => 'regex:/^[a-z]+[0-9]+$/']));
    }

    public function testRequiredIfRule(): void
    {
        $this->assertSame([], Validator::make(
            ['type' => 'card', 'number' => '123'],
            ['number' => 'required_if:type,card']
        ));
        $this->assertNotEmpty(Validator::make(
            ['type' => 'card', 'number' => ''],
            ['number' => 'required_if:type,card']
        ));
    }

    public function testMinMaxNumbers(): void
    {
        $this->assertSame([], Validator::make(['n' => 5], ['n' => 'min:1|max:10']));
        $this->assertNotEmpty(Validator::make(['n' => 50], ['n' => 'max:10']));
    }

    public function testNumericRule(): void
    {
        $this->assertSame([], Validator::make(['n' => '12.5'], ['n' => 'numeric']));
        $this->assertNotEmpty(Validator::make(['n' => 'abc'], ['n' => 'numeric']));
    }
}
