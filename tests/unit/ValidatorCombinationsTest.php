<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Validator;

final class ValidatorCombinationsTest extends TestCase
{
    public function testRequiredEmailCombination(): void
    {
        $errors = Validator::make(
            ['email' => 'not-an-email'],
            ['email' => 'required|email']
        );
        $this->assertArrayHasKey('email', $errors);
        $this->assertNotEmpty($errors['email']);
    }

    public function testValidEmailPasses(): void
    {
        $errors = Validator::make(
            ['email' => 'test@example.com'],
            ['email' => 'required|email']
        );
        $this->assertArrayNotHasKey('email', $errors);
    }

    public function testMinMaxCombination(): void
    {
        $errors = Validator::make(
            ['password' => '12345'],
            ['password' => 'min:8|max:20']
        );
        $this->assertArrayHasKey('password', $errors);
    }

    public function testMinMaxValidRange(): void
    {
        $errors = Validator::make(
            ['password' => 'abcdefgh'],
            ['password' => 'min:8|max:20']
        );
        $this->assertArrayNotHasKey('password', $errors);
    }

    public function testInWithOtherRules(): void
    {
        $errors = Validator::make(
            ['status' => 'pending'],
            ['status' => 'required|in:active,inactive,pending']
        );
        $this->assertArrayNotHasKey('status', $errors);
    }

    public function testInInvalidValue(): void
    {
        $errors = Validator::make(
            ['status' => 'unknown'],
            ['status' => 'required|in:active,inactive,pending']
        );
        $this->assertArrayHasKey('status', $errors);
    }

    public function testNumericWithMinMax(): void
    {
        $errors = Validator::make(
            ['age' => 25],
            ['age' => 'required|numeric|min:18|max:100']
        );
        $this->assertArrayNotHasKey('age', $errors);
    }

    public function testNumericBelowMin(): void
    {
        $errors = Validator::make(
            ['age' => 15],
            ['age' => 'required|numeric|min:18']
        );
        $this->assertArrayHasKey('age', $errors);
    }

    public function testIntegerValidation(): void
    {
        $errors = Validator::make(
            ['count' => 'abc'],
            ['count' => 'required|integer']
        );
        $this->assertArrayHasKey('count', $errors);
    }

    public function testIntegerValid(): void
    {
        $errors = Validator::make(
            ['count' => 42],
            ['count' => 'required|integer']
        );
        $this->assertArrayNotHasKey('count', $errors);
    }

    public function testUrlValidation(): void
    {
        $errors = Validator::make(
            ['website' => 'not-a-url'],
            ['website' => 'url']
        );
        $this->assertArrayHasKey('website', $errors);
    }

    public function testUrlValid(): void
    {
        $errors = Validator::make(
            ['website' => 'https://example.com'],
            ['website' => 'url']
        );
        $this->assertArrayNotHasKey('website', $errors);
    }

    public function testRegexPattern(): void
    {
        $errors = Validator::make(
            ['code' => 'ABC123'],
            ['code' => 'regex:/^[A-Z]{3}[0-9]{3}$/']
        );
        $this->assertArrayNotHasKey('code', $errors);
    }

    public function testRegexPatternInvalid(): void
    {
        $errors = Validator::make(
            ['code' => 'abc'],
            ['code' => 'regex:/^[A-Z]{3}[0-9]{3}$/']
        );
        $this->assertArrayHasKey('code', $errors);
    }

    public function testDateValidation(): void
    {
        $errors = Validator::make(
            ['born' => 'not-a-date'],
            ['born' => 'date']
        );
        $this->assertArrayHasKey('born', $errors);
    }

    public function testDateValid(): void
    {
        $errors = Validator::make(
            ['born' => '2000-01-01'],
            ['born' => 'date']
        );
        $this->assertArrayNotHasKey('born', $errors);
    }

    public function testMultipleFieldsValidation(): void
    {
        $errors = Validator::make(
            [
                'name' => 'John',
                'email' => 'valid@example.com',
                'age' => 25
            ],
            [
                'name' => 'required|min:2',
                'email' => 'required|email',
                'age' => 'required|numeric|min:18'
            ]
        );
        $this->assertEmpty($errors);
    }

    public function testMultipleFieldsWithErrors(): void
    {
        $errors = Validator::make(
            [
                'name' => '',
                'email' => 'invalid',
                'age' => 15
            ],
            [
                'name' => 'required|min:2',
                'email' => 'required|email',
                'age' => 'required|numeric|min:18'
            ]
        );
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
    }

    public function testCustomRule(): void
    {
        Validator::extend('phone', function ($value, $field, $input, $param) {
            return preg_match('/^\+?[0-9]{10,15}$/', (string) $value) ? true : ':field is not a valid phone number';
        });

        $errors = Validator::make(
            ['phone' => '1234567890'],
            ['phone' => 'phone']
        );
        $this->assertArrayNotHasKey('phone', $errors);
    }

    public function testCustomRuleInvalid(): void
    {
        Validator::extend('phone', function ($value, $field, $input, $param) {
            return preg_match('/^\+?[0-9]{10,15}$/', (string) $value) ? true : ':field is not a valid phone number';
        });

        $errors = Validator::make(
            ['phone' => '123'],
            ['phone' => 'phone']
        );
        $this->assertArrayHasKey('phone', $errors);
    }
}
