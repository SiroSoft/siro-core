<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Exception thrown when request validation fails.
 *
 * Caught by App::run() and converted to a 422 JSON response
 * with structured error messages.
 *
 * @package Siro\Core
 */
final class ValidationException extends RuntimeException
{
    /** @var array<string, array<int, string>> */
    private readonly array $errors;

    /**
     * @param array<string, array<int, string>> $errors
     */
    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422);
        $this->errors = $errors;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function toResponse(): Response
    {
        $payload = [
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
            'meta' => [
                'errors' => $this->errors,
            ],
        ];
        return new Response($payload, 422);
    }
}
