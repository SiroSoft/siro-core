<?php

declare(strict_types=1);

namespace Siro\Core\Mail;

use Siro\Core\Logger;

/**
 * No-op mail provider for local development and testing.
 *
 * Always succeeds without touching the network. Prefer Mail::fake() when
 * assertions on sent mails are needed; use this provider when the goal is
 * simply to silence delivery (MAIL_PROVIDER=null).
 */
final class NullMailProvider implements MailProvider
{
    /**
     * @param array<string, mixed> $mail
     */
    public function send(array $mail): bool
    {
        $to = isset($mail['to']) && is_string($mail['to']) ? $mail['to'] : '';
        Logger::request('MAIL', $to . ' (null provider, skipped)', 200, 0, '', '');
        return true;
    }
}
