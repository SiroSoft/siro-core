<?php

declare(strict_types=1);

namespace Siro\Core\Mail;

use RuntimeException;
use Siro\Core\Console;
use Siro\Core\Env;
use Siro\Core\Logger;
use Throwable;

/**
 * SMTP mail provider with STARTTLS and AUTH LOGIN support.
 *
 * Extracted from Mail so transports are swappable. Never throws for
 * transport failures: failures are logged and reported as false, per the
 * MailProvider contract.
 *
 * Config via .env:
 *   MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD,
 *   MAIL_ENCRYPTION=ssl|tls|starttls, MAIL_SSL_VERIFY, MAIL_DSN,
 *   MAIL_FROM_ADDRESS, MAIL_FROM_NAME
 */
final class SmtpMailProvider implements MailProvider
{
    /**
     * @param array<string, mixed> $mail
     */
    public function send(array $mail): bool
    {
        try {
            return $this->deliver($mail);
        } catch (Throwable $e) {
            Logger::warning('SMTP delivery failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param array<string, mixed> $mail
     */
    private function deliver(array $mail): bool
    {
        $to = self::stringValue($mail, 'to', '');
        $subject = self::stringValue($mail, 'subject', '');
        $body = self::stringValue($mail, 'body', '');
        $contentType = self::stringValue($mail, 'content_type', 'text/plain');
        $charset = self::stringValue($mail, 'charset', 'UTF-8');
        /** @var array<int, string> $cc */
        $cc = is_array($mail['cc'] ?? null) ? $mail['cc'] : [];
        /** @var array<int, string> $bcc */
        $bcc = is_array($mail['bcc'] ?? null) ? $mail['bcc'] : [];
        /** @var array<int, array{path: string, name: string, mime: string}> $attachments */
        $attachments = is_array($mail['attachments'] ?? null) ? $mail['attachments'] : [];

        $fromAddress = (string) Env::get('MAIL_FROM_ADDRESS', 'noreply@localhost');
        $fromName = (string) Env::get('MAIL_FROM_NAME', 'Siro API');

        $safeFromName = self::sanitizeHeader($fromName);
        $safeFromAddress = self::sanitizeAddress($fromAddress);
        $headers = [
            'From: ' . $safeFromName . ' <' . $safeFromAddress . '>',
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType . '; charset=' . $charset,
            'Content-Transfer-Encoding: base64',
            'X-Mailer: SiroPHP/' . self::sanitizeHeader((string) Env::get('APP_VERSION', Console::VERSION)),
        ];

        if ($attachments !== []) {
            $boundary = 'siro_boundary_' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $body = $this->buildMultipartBody($boundary, $body, $contentType, $charset, $attachments);
        }

        return $this->sendSmtp($to, $subject, $body, $headers, $cc, $bcc);
    }

    /**
     * @param array<string, mixed> $mail
     */
    private static function stringValue(array $mail, string $key, string $default): string
    {
        return isset($mail[$key]) && is_string($mail[$key]) ? $mail[$key] : $default;
    }

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
            Logger::warning('SMTP header injection blocked in address: ' . $address);
        }
        return $clean;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string> $cc
     * @param array<int, string> $bcc
     */
    private function sendSmtp(string $to, string $subject, string $body, array $headers, array $cc, array $bcc): bool
    {
        $host = (string) Env::get('MAIL_HOST', '127.0.0.1');
        $port = (int) Env::get('MAIL_PORT', '587');
        $username = (string) Env::get('MAIL_USERNAME', '');
        $password = (string) Env::get('MAIL_PASSWORD', '');
        $encryption = strtolower((string) Env::get('MAIL_ENCRYPTION', ''));

        $dsn = (string) Env::get('MAIL_DSN', '');
        if ($dsn !== '') {
            $parsed = parse_url($dsn);
            if (is_array($parsed) && isset($parsed['host'])) {
                $host = $parsed['host'];
                if (isset($parsed['port'])) {
                    $port = (int) $parsed['port'];
                }
                if (isset($parsed['user'])) {
                    $username = urldecode($parsed['user']);
                }
                if (isset($parsed['pass'])) {
                    $password = urldecode($parsed['pass']);
                }
            }
        }

        $prefix = $encryption === 'ssl' ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, 30);
        $meta = stream_get_meta_data($socket);
        if ($meta['timed_out']) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }

        try {
            $this->smtpReadResponse($socket);
            $this->smtpCommand($socket, 'EHLO localhost');
            $this->smtpReadResponse($socket);

            if ($encryption === 'tls' || $encryption === 'starttls') {
                $this->smtpCommand($socket, 'STARTTLS');
                $this->smtpReadResponse($socket);
                $sslContext = stream_context_create(['ssl' => [
                    'verify_peer' => filter_var(Env::get('MAIL_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),
                    'verify_peer_name' => filter_var(Env::get('MAIL_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),
                ]]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT, $sslContext)) {
                    throw new RuntimeException('SMTP STARTTLS negotiation failed');
                }
                $this->smtpCommand($socket, 'EHLO localhost');
                $this->smtpReadResponse($socket);
            }

            if ($username !== '' && $password !== '') {
                $this->smtpCommand($socket, 'AUTH LOGIN');
                $this->smtpReadResponse($socket);
                $this->smtpCommand($socket, base64_encode($username));
                $this->smtpReadResponse($socket);
                $this->smtpCommand($socket, base64_encode($password));
                $this->smtpReadResponse($socket);
            }

            $fromAddress = self::sanitizeAddress((string) Env::get('MAIL_FROM_ADDRESS', 'noreply@localhost'));
            $safeTo = self::sanitizeAddress($to);
            $safeSubject = self::sanitizeHeader($subject);
            $this->smtpCommand($socket, "MAIL FROM:<{$fromAddress}>");
            $this->smtpReadResponse($socket);
            $this->smtpCommand($socket, "RCPT TO:<{$safeTo}>");
            $this->smtpReadResponse($socket);

            foreach ($cc as $ccAddr) {
                $sanitizedCc = self::sanitizeAddress($ccAddr);
                $this->smtpCommand($socket, "RCPT TO:<{$sanitizedCc}>");
                $this->smtpReadResponse($socket);
            }
            foreach ($bcc as $bccAddr) {
                $sanitizedBcc = self::sanitizeAddress($bccAddr);
                $this->smtpCommand($socket, "RCPT TO:<{$sanitizedBcc}>");
                $this->smtpReadResponse($socket);
            }

            $this->smtpCommand($socket, 'DATA');
            $this->smtpReadResponse($socket);

            fwrite($socket, "Subject: {$safeSubject}\r\n");
            foreach ($headers as $header) {
                fwrite($socket, $header . "\r\n");
            }
            fwrite($socket, "\r\n");
            $body = str_replace("\r\n.", "\r\n..", $body);
            fwrite($socket, $body . "\r\n.\r\n");

            $this->smtpReadResponse($socket);
            $this->smtpCommand($socket, 'QUIT');
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
     *
     * @param array<int, array{path: string, name: string, mime: string}> $attachments
     */
    private function buildMultipartBody(string $boundary, string $body, string $contentType, string $charset, array $attachments): string
    {
        $result = "This is a multi-part message in MIME format.\r\n\r\n";
        $result .= "--{$boundary}\r\n";
        $result .= "Content-Type: {$contentType}; charset={$charset}\r\n";
        $result .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $result .= chunk_split(base64_encode($body), 76, "\r\n") . "\r\n";

        foreach ($attachments as $attachment) {
            $encoded = base64_encode((string) file_get_contents($attachment['path']));
            $result .= "--{$boundary}\r\n";
            $result .= "Content-Type: {$attachment['mime']}; name=\"{$attachment['name']}\"\r\n";
            $result .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n";
            $result .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $result .= chunk_split($encoded, 76, "\r\n") . "\r\n";
        }

        $result .= "--{$boundary}--\r\n";
        return $result;
    }

    /**
     * Send an SMTP command.
     */
    private function smtpCommand(mixed $socket, string $command): void
    {
        if (is_resource($socket)) {
            fwrite($socket, $command . "\r\n");
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
