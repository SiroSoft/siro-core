<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Exception thrown when a request carries a non-empty body that is not
 * parseable (e.g. invalid JSON) and the controller asks for validated data.
 *
 * Previously this surfaced as a misleading "field is required" validation
 * error. Caught by App::run() and converted to a 400 JSON response with
 * the machine-readable code "malformed_body".
 *
 * @package Siro\Core
 */
final class MalformedBodyException extends RuntimeException
{
    public function __construct(string $message = 'Malformed JSON request body')
    {
        parent::__construct($message, 400);
    }

    public function toResponse(): Response
    {
        return Response::error(
            $this->getMessage(),
            400,
            ['body' => ['The request body is not valid JSON.']],
            'malformed_body'
        );
    }
}
