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
        $to = isset($data['to']) && is_scalar($data['to']) ? (string) $data['to'] : '';
        $subject = isset($data['subject']) && is_scalar($data['subject']) ? (string) $data['subject'] : '';
        $body = isset($data['body']) && is_scalar($data['body']) ? (string) $data['body'] : '';
        $contentType = isset($data['content_type']) && is_scalar($data['content_type']) ? (string) $data['content_type'] : 'text/plain';

        $mail = Mail::to($to)->subject($subject);

        if ($contentType === 'text/html') {
            $mail->html($body);
        } else {
            $mail->text($body);
        }

        if (isset($data['reply_to']) && is_scalar($data['reply_to'])) {
            $mail->replyTo((string) $data['reply_to']);
        }

        foreach ((array) ($data['cc'] ?? []) as $ccAddr) {
            if (is_scalar($ccAddr)) {
                $mail->cc((string) $ccAddr);
            }
        }

        foreach ((array) ($data['bcc'] ?? []) as $bccAddr) {
            if (is_scalar($bccAddr)) {
                $mail->bcc((string) $bccAddr);
            }
        }

        foreach ((array) ($data['attachments'] ?? []) as $attachment) {
            if (is_array($attachment) && isset($attachment['path'])) {
                /** @var array<string, mixed> $attachment */
                $path = $attachment['path'];
                $name = $attachment['name'] ?? '';
                $pathStr = '';
                if (is_string($path)) {
                    $pathStr = $path;
                } elseif (is_scalar($path)) {
                    $pathStr = (string) $path;
                }
                $nameStr = is_scalar($name) ? (string) $name : '';
                $mail->attach($pathStr, $nameStr);
            }
        }

        $mail->send();
    }
}
