<?php

declare(strict_types=1);

namespace Siro\Core\Mail;

/**
 * Contract for mail delivery transports.
 *
 * Implementations must never throw for transport failures: log the failure
 * and return false instead, so non-critical notification paths can use
 * Mail::trySend() without try/catch blocks. Programming errors (empty
 * recipient, unreadable attachment) are validated by Mail before dispatch
 * and are not covered by this contract.
 *
 * Payload shape:
 *   array{
 *     to: string,
 *     subject: string,
 *     body: string,
 *     content_type: string,
 *     charset: string,
 *     cc: array<int, string>,
 *     bcc: array<int, string>,
 *     reply_to: string,
 *     attachments: array<int, array{path: string, name: string, mime: string}>
 *   }
 */
interface MailProvider
{
    /**
     * @param array<string, mixed> $mail
     */
    public function send(array $mail): bool;
}
