<?php

declare(strict_types=1);

namespace Siro\Core;

/**
 * Job class for sending queued emails.
 *
 * This is used internally by Mail::queue() and Mail::sendLater().
 * When the queue worker processes this job, it reconstructs the
 * Mail instance and sends the email.
 *
 * @package Siro\Core
 */
final class SendMailJob
{
    /**
     * Execute the queued email send.
     *
     * @param array<string, mixed> $data Serialized mail data
     */
    public function handle(array $data = []): void
    {
        $mail = Mail::to((string) ($data['to'] ?? ''))
            ->subject((string) ($data['subject'] ?? ''));

        $contentType = (string) ($data['content_type'] ?? 'text/plain');
        $body = (string) ($data['body'] ?? '');

        if ($contentType === 'text/html') {
            $mail->html($body);
        } else {
            $mail->text($body);
        }

        if (!empty($data['reply_to'])) {
            $mail->replyTo((string) $data['reply_to']);
        }

        foreach ((array) ($data['cc'] ?? []) as $ccAddr) {
            $mail->cc((string) $ccAddr);
        }

        foreach ((array) ($data['bcc'] ?? []) as $bccAddr) {
            $mail->bcc((string) $bccAddr);
        }

        foreach ((array) ($data['attachments'] ?? []) as $attachment) {
            if (is_array($attachment) && isset($attachment['path'])) {
                $mail->attach(
                    (string) $attachment['path'],
                    (string) ($attachment['name'] ?? '')
                );
            }
        }

        $mail->send();
    }
}
