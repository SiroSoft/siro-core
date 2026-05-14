<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Email sender with sendmail and SMTP support.
 *
 * Supports sendmail (PHP mail()) and SMTP with STARTTLS and AUTH LOGIN.
 * Can send emails directly or push to the queue for async delivery.
 *
 * Config via .env:
 *   MAIL_DRIVER=sendmail|smtp
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

    public static function reset(): void
    {
        self::$faked = false;
        self::$fakeMails = [];
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
        $instance->to = $address;
        return $instance;
    }

    /**
     * Set email subject.
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
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
        $this->cc[] = $address;
        return $this;
    }

    /**
     * Add a BCC recipient.
     */
    public function bcc(string $address): self
    {
        $this->bcc[] = $address;
        return $this;
    }

    /**
     * Set Reply-To address.
     */
    public function replyTo(string $address): self
    {
        $this->replyTo = $address;
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
        if (!is_file($path)) {
            throw new RuntimeException("Attachment file not found: {$path}");
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';
        if ($name === '') {
            $name = basename($path);
        }

        $this->attachments[] = [
            'path' => $path,
            'name' => $name,
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
            self::$fakeMails[] = [
                'to' => $this->to,
                'subject' => $this->subject,
                'body' => $this->body,
                'content_type' => $this->contentType,
                'cc' => $this->cc,
                'bcc' => $this->bcc,
                'reply_to' => $this->replyTo,
                'attachments' => $this->attachments,
            ];
            return true;
        }

        $driver = strtolower((string) Env::get('MAIL_DRIVER', 'sendmail'));

        try {
            $result = match ($driver) {
                'smtp' => $this->sendSmtp(),
                default => $this->sendSendmail(),
            };

            Logger::request('MAIL', $this->to, $result ? 200 : 500, 0, '', '');
            return $result;
        } catch (RuntimeException $e) {
            Logger::error($e);
            throw $e;
        }
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
     * Send via PHP's built-in mail() function.
     */
    private function sendSendmail(): bool
    {
        $fromAddress = (string) Env::get('MAIL_FROM_ADDRESS', 'noreply@localhost');
        $fromName = (string) Env::get('MAIL_FROM_NAME', 'Siro API');

        $headers = [
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'MIME-Version: 1.0',
            'Content-Type: ' . $this->contentType . '; charset=' . $this->charset,
            'Content-Transfer-Encoding: base64',
            'X-Mailer: SiroPHP/' . (Env::get('APP_VERSION', '0.8.4')),
        ];

        if ($this->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }

        foreach ($this->cc as $ccAddr) {
            $headers[] = 'CC: ' . $ccAddr;
        }

        // BCC recipients are added to the envelope (to parameter) but NOT to headers
        $allRecipients = $this->to;
        foreach ($this->bcc as $bccAddr) {
            $allRecipients .= ', ' . $bccAddr;
        }

        $body = chunk_split(base64_encode($this->body), 76, "\r\n");

        if ($this->attachments !== []) {
            $boundary = 'siro_boundary_' . bin2hex(random_bytes(8));
            $headers[2] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            $body = $this->buildMultipartBody($boundary);
        }

        try {
            $result = mail($allRecipients, $this->subject, $body, implode("\r\n", $headers));
        } catch (\Throwable $e) {
            \Siro\Core\Logger::error('mail() failed: ' . $e->getMessage());
            $result = false;
        }
        return $result;
    }

    /**
     * Send via SMTP directly using fsockopen.
     * No external dependencies required.
     */
    private function sendSmtp(): bool
    {
        $host = (string) Env::get('MAIL_HOST', '127.0.0.1');
        $port = (int) Env::get('MAIL_PORT', '587');
        $username = (string) Env::get('MAIL_USERNAME', '');
        $password = (string) Env::get('MAIL_PASSWORD', '');
        $fromAddress = (string) Env::get('MAIL_FROM_ADDRESS', 'noreply@localhost');
        $fromName = (string) Env::get('MAIL_FROM_NAME', 'Siro API');

        $errno = 0;
        $errstr = '';
        $socket = fsockopen($host, $port, $errno, $errstr, 30);

        if ($socket === false) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }

        try {
        $this->smtpReadResponse($socket);
        $this->smtpCommand($socket, "EHLO localhost");

        $serverInfo = $this->smtpReadResponse($socket);

        if ($username !== '' && $password !== '') {
            $this->smtpCommand($socket, "STARTTLS");
            $this->smtpReadResponse($socket);
            $sslContext = stream_context_create(['ssl' => [
                'verify_peer' => filter_var(Env::get('MAIL_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),
                'verify_peer_name' => filter_var(Env::get('MAIL_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),
            ]]);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT, $sslContext);
            $this->smtpCommand($socket, "EHLO localhost");
            $this->smtpReadResponse($socket);

            $this->smtpCommand($socket, "AUTH LOGIN");
            $this->smtpReadResponse($socket);
            $this->smtpCommand($socket, base64_encode($username));
            $this->smtpReadResponse($socket);
            $this->smtpCommand($socket, base64_encode($password));
            $this->smtpReadResponse($socket);
        }

        $this->smtpCommand($socket, "MAIL FROM:<{$fromAddress}>");
        $this->smtpReadResponse($socket);
        $this->smtpCommand($socket, "RCPT TO:<{$this->to}>");
        $this->smtpReadResponse($socket);

        foreach ($this->cc as $ccAddr) {
            $this->smtpCommand($socket, "RCPT TO:<{$ccAddr}>");
            $this->smtpReadResponse($socket);
        }
        foreach ($this->bcc as $bccAddr) {
            $this->smtpCommand($socket, "RCPT TO:<{$bccAddr}>");
            $this->smtpReadResponse($socket);
        }

        $this->smtpCommand($socket, "DATA");
        $this->smtpReadResponse($socket);

        $headers = "From: {$fromName} <{$fromAddress}>\r\n";
        $headers .= "Reply-To: " . ($this->replyTo ?: $fromAddress) . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        if ($this->attachments !== []) {
            $boundary = 'siro_boundary_' . bin2hex(random_bytes(8));
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
            $headers .= "Subject: {$this->subject}\r\n";
            $headers .= "\r\n";
            fwrite($socket, $headers . $this->buildMultipartBody($boundary) . "\r\n.\r\n");
        } else {
            $headers .= "Content-Type: {$this->contentType}; charset={$this->charset}\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $headers .= "Subject: {$this->subject}\r\n";
            $headers .= "\r\n";
            $encodedBody = chunk_split(base64_encode($this->body), 76, "\r\n");
            fwrite($socket, $headers . $encodedBody . "\r\n.\r\n");
        }

        $this->smtpReadResponse($socket);
        $this->smtpCommand($socket, "QUIT");
        $this->smtpReadResponse($socket);

        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        return true;
    }

    /**
     * Build a multipart/mixed body with inline content and attachments.
     */
    private function buildMultipartBody(string $boundary): string
    {
        $body = "This is a multi-part message in MIME format.\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$this->contentType}; charset={$this->charset}\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($this->body), 76, "\r\n") . "\r\n";

        foreach ($this->attachments as $attachment) {
            $encoded = base64_encode((string) file_get_contents($attachment['path']));
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$attachment['mime']}; name=\"{$attachment['name']}\"\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split($encoded, 76, "\r\n") . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";
        return $body;
    }

    /**
     * Send an SMTP command.
     */
    private function smtpCommand(mixed $socket, string $command): void
    {
        if ($command !== 'QUIT') {
            if (is_resource($socket)) {
                fwrite($socket, $command . "\r\n");
            }
        }
    }

    /**
     * Read SMTP response and check for errors.
     *
     * @throws RuntimeException on error
     */
    private function smtpReadResponse(mixed $socket): string
    {
        if (!is_resource($socket)) {
            throw new \RuntimeException('SMTP socket is not a valid resource');
        }
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code >= 400) {
            throw new RuntimeException("SMTP error: {$response}");
        }

        return $response;
    }
}
