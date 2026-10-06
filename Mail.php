<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;
use Siro\Core\Mail\MailProvider;
use Siro\Core\Mail\NullMailProvider;
use Siro\Core\Mail\SmtpMailProvider;
use Throwable;

/**
 * Email sender with swappable transports (Siro\Core\Mail\MailProvider).
 *
 * Default transport is SMTP with STARTTLS and AUTH LOGIN. Delivery can be
 * silenced with MAIL_PROVIDER=null, or replaced via Mail::setProvider()
 * with any MailProvider implementation (e.g. API-based drivers in app code).
 * Can send emails directly or push to the queue for async delivery.
 *
 * Config via .env:
 *   MAIL_PROVIDER=smtp|null
 *   MAIL_HOST=smtp.example.com
 *   MAIL_PORT=587
 *   MAIL_USERNAME=user
 *   MAIL_PASSWORD=pass
 *   MAIL_FROM_ADDRESS=noreply@example.com
 *   MAIL_FROM_NAME="Siro API"
 *
 * Usage:
 *   Mail::to('user@example.com')
 *       ->subject('Welcome')
 *       ->html('<h1>Hi</h1>')
 *       ->send();
 *
 *   // Send with queue (async):
 *   Mail::to('user@example.com')
 *       ->subject('Welcome')
 *       ->html('<h1>Hi</h1>')
 *       ->queue();
 *
 *   // Delayed delivery:
 *   Mail::to('user@example.com')
 *       ->subject('Welcome')
 *       ->html('<h1>Hi</h1>')
 *       ->sendLater(3600); // 1 hour later
 *
 * @package Siro\Core
 */
final class Mail
{
    private static bool $faked = false;
    /** @var array<int, array{to:string,subject:string,body:string}> */
    private static array $fakeMails = [];
    private static ?MailProvider $provider = null;
    /** Strip SMTP-injection characters (\r\n) from header values */
    private static function sanitizeHeader(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n", "\0"], '', $value);
    }

    /** Strip SMTP-injection from an email address */
    private static function sanitizeAddress(string $address): string
    {
        $clean = str_replace(["\r\n", "\r", "\n", "\0", ' ', "\t"], '', $address);
        if ($clean !== $address) {
            Logger::warning("SMTP header injection blocked in address: " . $address);
        }
        return $clean;
    }

    public static function reset(): void
    {
        self::$faked = false;
        self::$fakeMails = [];
        self::$provider = null;
    }

    /**
     * Inject a custom mail transport (e.g. API-based providers in app code).
     * Pass null to fall back to env-based resolution (MAIL_PROVIDER).
     */
    public static function setProvider(?MailProvider $provider): void
    {
        self::$provider = $provider;
    }

    /**
     * Resolve the active provider: explicit injection first, then the
     * MAIL_PROVIDER env var ('smtp' default, 'null' for no-op).
     */
    public static function resolveProvider(): MailProvider
    {
        if (self::$provider !== null) {
            return self::$provider;
        }

        $name = strtolower((string) Env::get('MAIL_PROVIDER', 'smtp'));
        return match ($name) {
            'smtp', '' => new SmtpMailProvider(),
            'null', 'noop', 'log' => new NullMailProvider(),
            default => throw new RuntimeException("Unknown mail provider: {$name} (expected smtp|null)"),
        };
    }

    public static function fake(): void
    {
        self::$faked = true;
        self::$fakeMails = [];
    }

    /** @return array<int, array{to:string,subject:string,body:string}> */
    public static function getFakedMails(): array
    {
        return self::$fakeMails;
    }

    public static function assertSent(string $subject): void
    {
        $matched = array_filter(self::$fakeMails, fn($m) => $m['subject'] === $subject);
        \PHPUnit\Framework\Assert::assertGreaterThan(0, count($matched), "Mail with subject '{$subject}' was not sent.");
    }

    public static function assertSentTo(string $address): void
    {
        $matched = array_filter(self::$fakeMails, fn($m) => $m['to'] === $address);
        \PHPUnit\Framework\Assert::assertGreaterThan(0, count($matched), "Mail to '{$address}' was not sent.");
    }

    public static function assertNotSentTo(string $address): void
    {
        $matched = array_filter(self::$fakeMails, fn($m) => $m['to'] === $address);
        \PHPUnit\Framework\Assert::assertCount(0, $matched, "Mail to '{$address}' was sent unexpectedly.");
    }

    private string $to = '';
    private string $subject = '';
    private string $body = '';
    private string $contentType = 'text/plain';
    /** @var array<int, string> */
    private array $cc = [];
    /** @var array<int, string> */
    private array $bcc = [];
    /** @var array<int, array{path: string, name: string, mime: string}> */
    private array $attachments = [];
    private string $replyTo = '';
    private string $charset = 'UTF-8';

    /**
     * Set recipient.
     */
    public static function to(string $address): self
    {
        $instance = new self();
        $instance->to = self::sanitizeAddress($address);
        return $instance;
    }

    /**
     * Set email subject.
     */
    public function subject(string $subject): self
    {
        $this->subject = self::sanitizeHeader($subject);
        return $this;
    }

    /**
     * Set HTML body.
     */
    public function html(string $html): self
    {
        $this->body = $html;
        $this->contentType = 'text/html';
        return $this;
    }

    /**
     * Set plain text body.
     */
    public function text(string $text): self
    {
        $this->body = $text;
        $this->contentType = 'text/plain';
        return $this;
    }

    /**
     * Add a CC recipient.
     */
    public function cc(string $address): self
    {
        $this->cc[] = self::sanitizeAddress($address);
        return $this;
    }

    /**
     * Add a BCC recipient.
     */
    public function bcc(string $address): self
    {
        $this->bcc[] = self::sanitizeAddress($address);
        return $this;
    }

    /**
     * Set Reply-To address.
     */
    public function replyTo(string $address): self
    {
        $this->replyTo = self::sanitizeAddress($address);
        return $this;
    }

    /**
     * Attach a file to the email.
     *
     * @param string $path Absolute path to the file
     * @param string $name Optional custom filename (default: basename of path)
     */
    public function attach(string $path, string $name = ''): self
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException("Attachment file not found: {$path}");
        }

        $base = defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH) ? SIRO_BASE_PATH : (string) getcwd();
        $base = rtrim($base, DIRECTORY_SEPARATOR);
        if (!str_starts_with($real, $base)) {
            throw new RuntimeException('Access denied: attachment file is outside project directory');
        }

        $mime = mime_content_type($real) ?: 'application/octet-stream';
        if ($name === '') {
            $name = basename($path);
        }

        $this->attachments[] = [
            'path' => $real,
            'name' => self::sanitizeHeader($name),
            'mime' => $mime,
        ];

        return $this;
    }

    /**
     * Send the email immediately.
     *
     * @throws RuntimeException on failure
     */
    public function send(): bool
    {
        if ($this->to === '' || $this->body === '') {
            throw new RuntimeException('Recipient and body are required.');
        }

        if (self::$faked) {
            self::$fakeMails[] = $this->payload();
            return true;
        }

        try {
            $result = $this->sendMail();

            Logger::request('MAIL', $this->to, $result ? 200 : 500, 0, '', '');
            return $result;
        } catch (RuntimeException $e) {
            Logger::error($e);
            throw $e;
        }
    }

    /**
     * Attempt delivery without ever throwing. Returns false when the
     * message is incomplete or the transport fails. Intended for
     * non-critical notification paths.
     */
    public function trySend(): bool
    {
        try {
            if ($this->to === '' || $this->body === '') {
                return false;
            }

            if (self::$faked) {
                self::$fakeMails[] = $this->payload();
                return true;
            }

            $result = self::resolveProvider()->send($this->payload());
            Logger::request('MAIL', $this->to, $result ? 200 : 500, 0, '', '');
            return $result;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{to:string,subject:string,body:string,content_type:string,charset:string,cc:array<int,string>,bcc:array<int,string>,reply_to:string,attachments:array<int,array{path:string,name:string,mime:string}>}
     */
    private function payload(): array
    {
        return [
            'to' => $this->to,
            'subject' => $this->subject,
            'body' => $this->body,
            'content_type' => $this->contentType,
            'charset' => $this->charset,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'reply_to' => $this->replyTo,
            'attachments' => $this->attachments,
        ];
    }

    /**
     * Push the email to the queue for async delivery.
     *
     * Requires the jobs table to exist (run migrations first).
     * The worker processes it with: php siro queue:work
     */
    public function queue(int $delay = 0): void
    {
        $mailData = [
            'to' => $this->to,
            'subject' => $this->subject,
            'body' => $this->body,
            'content_type' => $this->contentType,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'attachments' => $this->attachments,
            'reply_to' => $this->replyTo,
            'charset' => $this->charset,
        ];

        Queue::push(SendMailJob::class, $mailData, $delay);
    }

    /**
     * Send the email after a delay.
     * Uses the queue system internally.
     */
    public function sendLater(int $delaySeconds = 3600): void
    {
        $this->queue($delaySeconds);
    }

    /**
     * Deliver via the resolved provider. Throws on transport failure,
     * preserving the historical Mail::send() contract.
     */
    private function sendMail(): bool
    {
        $provider = self::resolveProvider();
        if (!$provider->send($this->payload())) {
            throw new RuntimeException('SMTP delivery failed (' . $provider::class . ')');
        }

        return true;
    }
}
