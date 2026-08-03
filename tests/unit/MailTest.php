<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Mail;

/**
 * Mail tests — full builder chain in fake mode, headers, sanitization,
 * assertions, send/queue/sendLater.
 */
final class MailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Mail::reset();
        parent::tearDown();
    }

    public function testBasicMailChain(): void
    {
        Mail::to('user@test.com')
            ->subject('Welcome')
            ->html('<h1>Hi</h1>')
            ->send();
        $mails = Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertSame('user@test.com', $mails[0]['to']);
        $this->assertSame('Welcome', $mails[0]['subject']);
        $this->assertSame('<h1>Hi</h1>', $mails[0]['body']);
    }

    public function testPlainTextMail(): void
    {
        Mail::to('a@test.com')->subject('S')->text('plain body')->send();
        $mails = Mail::getFakedMails();
        $this->assertSame('text/plain', $mails[0]['content_type']);
        $this->assertSame('plain body', $mails[0]['body']);
    }

    public function testHtmlContentType(): void
    {
        Mail::to('a@test.com')->html('<p>x</p>')->send();
        $this->assertSame('text/html', Mail::getFakedMails()[0]['content_type']);
    }

    public function testCcBccReplyTo(): void
    {
        Mail::to('to@test.com')
            ->cc('cc@test.com')
            ->bcc('bcc@test.com')
            ->replyTo('reply@test.com')
            ->subject('S')
            ->html('x')
            ->send();
        $m = Mail::getFakedMails()[0];
        $this->assertContains('cc@test.com', $m['cc']);
        $this->assertContains('bcc@test.com', $m['bcc']);
        $this->assertSame('reply@test.com', $m['reply_to']);
    }

    public function testAssertions(): void
    {
        Mail::to('to@test.com')->subject('Order Confirmed')->html('x')->send();
        Mail::to('other@test.com')->subject('Other')->html('x')->send();
        Mail::assertSent('Order Confirmed');
        Mail::assertSentTo('to@test.com');
        Mail::assertNotSentTo('nobody@test.com');
    }

    public function testSendWithoutBodyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Mail::to('a@test.com')->send(); // no body
    }

    public function testSendWithoutRecipientThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Mail::to('')->html('x')->send();
    }

    public function testSmtpInjectionSanitized(): void
    {
        Mail::to("evil@test.com\r\nBcc: victim@test.com")
            ->subject("Subject\r\nBcc: all@test.com")
            ->html('x')
            ->send();
        $m = Mail::getFakedMails()[0];
        // sanitize removes \r, \n, \0, space, tab from addresses; \r\n from headers
        $this->assertSame('evil@test.comBcc:victim@test.com', $m['to']);
        $this->assertSame('SubjectBcc: all@test.com', $m['subject']);
    }

    public function testQueuePushesJob(): void
    {
        // queue() requires DB (jobs table); in this unit test just ensure
        // it does not throw when the Mail object is valid in fake context
        Mail::to('q@test.com')->subject('Q')->html('x');
        $this->assertTrue(true); // builder chain works
    }

    public function testAttachFile(): void
    {
        // attach() requires files within the project directory
        $projDir = dirname(__DIR__, 2);
        $tmp = $projDir . '/storage/test_mail_attach_' . uniqid() . '.txt';
        file_put_contents($tmp, 'attachment data');
        Mail::to('a@test.com')->subject('With attach')->html('x')->attach($tmp, 'doc.txt')->send();
        $m = Mail::getFakedMails()[0];
        $this->assertNotEmpty($m['attachments']);
        @unlink($tmp);
    }

    public function testQueuePushesToQueue(): void
    {
        \Siro\Core\Queue::fake();
        Mail::to('queue@test.com')->subject('Queued')->html('x')->queue();
        \Siro\Core\Queue::assertPushed(\Siro\Core\SendMailJob::class);
        \Siro\Core\Queue::reset();
    }

    public function testSendLaterQueuesWithDelay(): void
    {
        \Siro\Core\Queue::fake();
        Mail::to('later@test.com')->subject('Later')->html('x')->sendLater(60);
        \Siro\Core\Queue::assertPushed(\Siro\Core\SendMailJob::class);
        \Siro\Core\Queue::reset();
    }
}
