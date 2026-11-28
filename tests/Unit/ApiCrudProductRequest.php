<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\FormRequest;

final class ApiCrudProductRequest extends FormRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'price' => 'required|numeric',
            'status' => 'required|string',
        ];
    }
}
