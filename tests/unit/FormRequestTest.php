<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\FormRequest;
use Siro\Core\Request;
use Siro\Core\ValidationException;

final class FormRequestTest extends TestCase
{
    public function testValidatePassesOnValidData(): void
    {
        $request = new Request('POST', '/test', [], [], ['email' => 'test@example.com', 'name' => 'John']);

        $form = new class($request) extends FormRequest {
            public function rules(): array
            {
                return [
                    'email' => 'required|email',
                    'name' => 'required|min:2',
                ];
            }
        };

        $data = $form->validated();
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('name', $data);
    }

    public function testValidateFailsOnInvalidData(): void
    {
        $request = new Request('POST', '/test', [], [], ['email' => 'not-an-email']);

        $form = new class($request) extends FormRequest {
            public function rules(): array
            {
                return [
                    'email' => 'required|email',
                ];
            }
        };

        $this->expectException(ValidationException::class);
        $form->validated();
    }

    public function testAuthorizeReturnsTrueByDefault(): void
    {
        $request = new Request('GET', '/test');
        $form = new class($request) extends FormRequest {
            public function rules(): array
            {
                return ['id' => 'required|integer'];
            }
        };

        $this->assertTrue($form->authorize());
    }

    public function testMessagesCanBeCustomized(): void
    {
        $request = new Request('POST', '/test', [], [], ['email' => '']);
        $form = new class($request) extends FormRequest {
            public function rules(): array
            {
                return ['email' => 'required'];
            }
            public function messages(): array
            {
                return ['email.required' => 'The email address is required.'];
            }
        };

        $this->expectException(ValidationException::class);
        $form->validated();
    }

    public function testGetReturnsValue(): void
    {
        $request = new Request('POST', '/test', [], [], ['key' => 'value']);
        $form = new class($request) extends FormRequest {
            public function rules(): array
            {
                return ['key' => 'required'];
            }
        };

        $validated = $form->validated();
        $this->assertSame('value', $form->get('key'));
    }

    public function testGetReturnsDefaultForMissing(): void
    {
        $request = new Request('GET', '/test');
        $form = new class($request) extends FormRequest {
            public function rules(): array
            {
                return [];
            }
        };

        $this->assertNull($form->get('missing'));
    }
}
