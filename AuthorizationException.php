<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Exception thrown when authorization fails (Gate::authorize()).
 *
 * Caught by App::run() and converted to a 403 JSON response with the
 * machine-readable code "forbidden".
 *
 * @package Siro\Core
 */
final class AuthorizationException extends RuntimeException
{
    public function __construct(string $message = 'This action is forbidden')
    {
        parent::__construct($message, 403);
    }

    public function toResponse(): Response
    {
        return Response::error(
            $this->getMessage(),
            403,
            ['authorization' => ['This action is forbidden.']],
            'forbidden'
        );
    }
}
