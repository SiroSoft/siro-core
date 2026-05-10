<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Base form request for validated controller input.
 *
 * Extend this class, define rules() and authorize() methods,
 * then type-hint in controller methods for automatic validation.
 *
 * @package Siro\Core
 */
abstract class FormRequest
{
    protected Request $request;
    /** @var array<string, mixed> */ protected array $validated = [];
    /** @var array<string, mixed> */ protected array $errors = [];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /** @return array<string, string> */ abstract public function rules(): array;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */ public function messages(): array
    {
        return [];
    }

    /** @return array<string, mixed> */ public function validated(): array
    {
        if ($this->validated === []) {
            $this->validate();
        }
        return $this->validated;
    }

    /** @return array<string, mixed> */ public function errors(): array
    {
        return $this->errors;
    }

    public function fails(): bool
    {
        if ($this->validated === []) {
            $this->validate();
        }
        return $this->errors !== [];
    }

    /** @return array<string, mixed> */ public function validate(): array
    {
        if ($this->validated !== []) {
            return $this->validated;
        }

        if (!$this->authorize()) {
            throw new ValidationException(['authorization' => ['Unauthorized.']]);
        }

        $this->validated = $this->request->validate($this->rules());
        return $this->validated;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->validated[$key] ?? $this->request->input($key, $default);
    }

    /** @return array<string, mixed> */ public function all(): array
    {
        return $this->validated !== [] ? $this->validated : $this->request->body();
    }
}
