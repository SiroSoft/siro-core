<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Validator;

/**
 * Validator Unit Tests
 * 
 * Tests all validation rules and edge cases
 */
final class ValidatorTest extends TestCase
{
    /**
     * Test required rule
     */
    public function testRequiredRuleFailsOnEmpty(): void
    {
        $errors = Validator::make(
            ['name' => ''],
            ['name' => 'required']
        );

        $this->assertArrayHasKey('name', $errors);
    }

    public function testRequiredRulePassesWithValue(): void
    {
        $errors = Validator::make(
            ['name' => 'John'],
            ['name' => 'required']
        );

        $this->assertEmpty($errors);
    }

    /**
     * Test email rule
     */
    public function testEmailRuleValidatesFormat(): void
    {
        // Valid emails
        $errors = Validator::make(
            ['email' => 'john@example.com'],
            ['email' => 'email']
        );
        $this->assertEmpty($errors);

        // Invalid emails
        $errors = Validator::make(
            ['email' => 'invalid-email'],
            ['email' => 'email']
        );
        $this->assertArrayHasKey('email', $errors);
    }

    /**
     * Test min rule for strings
     */
    public function testMinRuleForStrings(): void
    {
        $errors = Validator::make(
            ['password' => '12345'],
            ['password' => 'min:8']
        );

        $this->assertArrayHasKey('password', $errors);
    }

    public function testMinRulePassesWhenValid(): void
    {
        $errors = Validator::make(
            ['password' => '12345678'],
            ['password' => 'min:8']
        );

        $this->assertEmpty($errors);
    }

    /**
     * Test max rule for strings
     */
    public function testMaxRuleForStrings(): void
    {
        $errors = Validator::make(
            ['username' => 'verylongusernamethatexceedslimit'],
            ['username' => 'max:10']
        );

        $this->assertArrayHasKey('username', $errors);
    }

    /**
     * Test numeric min/max rules
     */
    public function testNumericMinRule(): void
    {
        $errors = Validator::make(
            ['age' => 15],
            ['age' => 'min:18']
        );

        $this->assertArrayHasKey('age', $errors);
    }

    public function testNumericMaxRule(): void
    {
        $errors = Validator::make(
            ['quantity' => 150],
            ['quantity' => 'max:100']
        );

        $this->assertArrayHasKey('quantity', $errors);
    }

    /**
     * Test in rule
     */
    public function testInRuleValidatesValues(): void
    {
        $errors = Validator::make(
            ['status' => 'invalid'],
            ['status' => 'in:active,inactive,pending']
        );

        $this->assertArrayHasKey('status', $errors);
    }

    public function testInRulePassesWithValidValue(): void
    {
        $errors = Validator::make(
            ['status' => 'active'],
            ['status' => 'in:active,inactive,pending']
        );

        $this->assertEmpty($errors);
    }

    /**
     * Test confirmed rule
     */
    public function testConfirmedRuleMatchesConfirmation(): void
    {
        $errors = Validator::make(
            [
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ],
            ['password' => 'confirmed']
        );

        $this->assertEmpty($errors);
    }

    public function testConfirmedRuleFailsOnMismatch(): void
    {
        $errors = Validator::make(
            [
                'password' => 'secret123',
                'password_confirmation' => 'different',
            ],
            ['password' => 'confirmed']
        );

        $this->assertArrayHasKey('password', $errors);
    }

    /**
     * Test multiple rules on same field
     */
    public function testMultipleRulesOnSameField(): void
    {
        $errors = Validator::make(
            ['email' => 'invalid'],
            ['email' => 'required|email|min:5']
        );

        $this->assertArrayHasKey('email', $errors);
    }

    public function testMultipleRulesAllPass(): void
    {
        $errors = Validator::make(
            ['email' => 'john@example.com'],
            ['email' => 'required|email|min:5']
        );

        $this->assertEmpty($errors);
    }

    /**
     * Test optional fields (not required)
     */
    public function testOptionalFieldNotValidatedWhenEmpty(): void
    {
        $errors = Validator::make(
            ['email' => ''],
            ['email' => 'email']  // Not required
        );

        $this->assertEmpty($errors);
    }

    /**
     * Test array data validation
     */
    public function testArrayDataValidation(): void
    {
        $errors = Validator::make(
            [
                'name' => 'John',
                'email' => 'john@example.com',
                'age' => 25,
            ],
            [
                'name' => 'required|min:3',
                'email' => 'required|email',
                'age' => 'required|min:18',
            ]
        );

        $this->assertEmpty($errors);
    }

    /**
     * Test validation returns empty array on success
     */
    public function testValidationReturnsEmptyArrayOnSuccess(): void
    {
        $errors = Validator::make(
            ['field' => 'value'],
            ['field' => 'required']
        );

        $this->assertIsArray($errors);
        $this->assertEmpty($errors);
    }

    /**
     * Test validation error format
     */
    public function testValidationErrorFormat(): void
    {
        $errors = Validator::make(
            ['field' => ''],
            ['field' => 'required']
        );

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('field', $errors);
        $this->assertIsArray($errors['field']);
    }

    /**
     * Test case sensitivity
     */
    public function testCaseSensitivity(): void
    {
        $errors = Validator::make(
            ['Email' => 'test@example.com'],
            ['email' => 'required|email']  // lowercase key
        );

        // Should fail because 'Email' != 'email'
        $this->assertArrayHasKey('email', $errors);
    }

    /**
     * Test with null values
     */
    public function testWithNullValues(): void
    {
        $errors = Validator::make(
            ['field' => null],
            ['field' => 'required']
        );

        $this->assertArrayHasKey('field', $errors);
    }

    /**
     * Test boolean-like values
     */
    public function testBooleanLikeValues(): void
    {
        $errors = Validator::make(
            ['active' => 1],
            ['active' => 'required']
        );

        $this->assertEmpty($errors);
    }
}
