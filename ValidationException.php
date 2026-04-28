<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Exception thrown when validation fails.
 * Automatically returns 422 response with validation errors.
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
        return Response::error($this->getMessage(), 422, $this->errors);
    }
}
