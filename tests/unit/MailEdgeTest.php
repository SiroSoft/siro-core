<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;
use Siro\Core\Mail;

/**
 * Mail edge cases: attach() error branches, fake send with attachments.
 */
final class MailEdgeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Mail::reset();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_mail_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        Mail::reset();
        if (is_dir($this->tmpDir)) {
            @unlink($this->tmpDir . DIRECTORY_SEPARATOR . 'attach.txt');
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testAttachMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Attachment file not found');
        Mail::to('a@test.com')->subject('s')->text('b')->attach($this->tmpDir . DIRECTORY_SEPARATOR . 'nope.txt');
    }

    public function testAttachOutsideProjectThrows(): void
    {
        // Attaching a file outside the project dir triggers access denial.
        $outside = tempnam(sys_get_temp_dir(), 'siro_out');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Access denied');
            Mail::to('a@test.com')->subject('s')->text('b')->attach($outside);
        } finally {
            @unlink($outside);
        }
    }

    public function testFakeSendWithAttachment(): void
    {
        Mail::fake();
        $projDir = dirname(__DIR__, 2);
        $attachFile = $projDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail_edge_attach_' . uniqid('', true) . '.txt';
        file_put_contents($attachFile, 'hello attachment content');

        try {
            $mail = Mail::to('recipient@test.com')
                ->subject('Test Subject')
                ->text('Body text')
                ->attach($attachFile, 'custom-name.txt')
                ->cc('cc@test.com')
                ->bcc('bcc@test.com')
                ->replyTo('reply@test.com');

            $this->assertTrue($mail->send());

            $faked = Mail::getFakedMails();
            $this->assertCount(1, $faked);
            $this->assertSame('recipient@test.com', $faked[0]['to']);
            $this->assertSame('Test Subject', $faked[0]['subject']);
            $this->assertSame('Body text', $faked[0]['body']);
            $this->assertCount(1, $faked[0]['attachments']);
            $this->assertSame('custom-name.txt', $faked[0]['attachments'][0]['name']);
        } finally {
            @unlink($attachFile);
        }
    }

    public function testFakeSendHtml(): void
    {
        Mail::fake();
        $mail = Mail::to('x@test.com')->subject('s')->html('<p>hi</p>');
        $this->assertTrue($mail->send());
        $faked = Mail::getFakedMails();
        $this->assertSame('text/html', $faked[0]['content_type']);
    }

    public function testAssertNotSentTo(): void
    {
        Mail::fake();
        Mail::to('real@test.com')->subject('s')->text('b')->send();
        Mail::assertNotSentTo('other@test.com');
        $this->assertCount(1, Mail::getFakedMails());
    }
}
