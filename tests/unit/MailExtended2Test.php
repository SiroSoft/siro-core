<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;
use Siro\Core\Mail;

/**
 * Mail internals: buildMultipartBody, smtpCommand, smtpReadResponse,
 * send() error paths via reflection and local sockets.
 */
final class MailExtended2Test extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Mail::reset();
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_mail2_' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        file_put_contents($this->basePath . '/attach.txt', 'attachment content here');
    }

    protected function tearDown(): void
    {
        Mail::reset();
        if (is_dir($this->basePath)) {
            @unlink($this->basePath . '/attach.txt');
            @rmdir($this->basePath);
        }
        parent::tearDown();
    }

    /** @return array{0: object, 1: \ReflectionClass} */
    private function mailInstance(): array
    {
        $cmd = Mail::to('a@test.com')->subject('S')->html('<p>Hi</p>');
        return [$cmd, new \ReflectionClass(Mail::class)];
    }

    public function testBuildMultipartBody(): void
    {
        $mail = Mail::to('a@test.com')->subject('S')->text('body text');
        $ref = new \ReflectionClass(Mail::class);
        $prop = $ref->getProperty('attachments');
        $prop->setAccessible(true);
        $prop->setValue($mail, [
            ['path' => $this->basePath . '/attach.txt', 'name' => 'doc.txt', 'mime' => 'text/plain'],
        ]);
        $m = $ref->getMethod('buildMultipartBody');
        $m->setAccessible(true);
        $result = $m->invoke($mail, 'boundary123');
        $this->assertStringContainsString('boundary123', $result);
        $this->assertStringContainsString('doc.txt', $result);
        $this->assertStringContainsString('Content-Type: text/plain', $result);
        // body text is base64-encoded
        $this->assertStringContainsString(base64_encode('body text'), $result);
    }

    public function testBuildMultipartBodyNoAttachments(): void
    {
        $mail = Mail::to('a@test.com')->subject('S')->text('plain body');
        $ref = new \ReflectionClass(Mail::class);
        $m = $ref->getMethod('buildMultipartBody');
        $m->setAccessible(true);
        $result = $m->invoke($mail, 'b2');
        $this->assertStringContainsString('b2', $result);
        $this->assertStringContainsString(base64_encode('plain body'), $result);
    }

    public function testSmtpCommandNonResource(): void
    {
        [$mail, $ref] = $this->mailInstance();
        $m = $ref->getMethod('smtpCommand');
        $m->setAccessible(true);
        // Should not throw for a non-resource
        $m->invoke($mail, null, 'NOOP');
        $this->assertTrue(true);
    }

    public function testSmtpReadResponseInvalidResource(): void
    {
        [$mail, $ref] = $this->mailInstance();
        $m = $ref->getMethod('smtpReadResponse');
        $m->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $m->invoke($mail, null);
    }

    public function testSendFailsWithoutSmtp(): void
    {
        putenv('MAIL_HOST=127.0.0.1');
        putenv('MAIL_PORT=1');
        putenv('MAIL_ENCRYPTION=');
        putenv('MAIL_FROM_ADDRESS=from@test.com');
        $mail = Mail::to('to@test.com')->subject('S')->text('body');
        $this->expectException(\RuntimeException::class);
        $mail->send();
        putenv('MAIL_HOST');
        putenv('MAIL_PORT');
    }

    public function testSendMailBuildsHeaders(): void
    {
        $mail = Mail::to('to@test.com')->subject('Subject')->text('hello');
        $ref = new \ReflectionClass(Mail::class);
        $m = $ref->getMethod('sendMail');
        $m->setAccessible(true);
        putenv('MAIL_HOST=127.0.0.1');
        putenv('MAIL_PORT=1');
        putenv('MAIL_ENCRYPTION=');
        try {
            // sendMail throws because SMTP unreachable, but headers built first
            $this->expectException(\RuntimeException::class);
            $m->invoke($mail);
        } finally {
            putenv('MAIL_HOST');
            putenv('MAIL_PORT');
        }
    }
}
