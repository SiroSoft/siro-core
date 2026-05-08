<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Mail;
use Siro\Core\Queue;

final class MailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
    }

    public function testFakeCreatesEmptyState(): void
    {
        $mails = Mail::getFakedMails();
        $this->assertEmpty($mails);
    }

    public function testSendStoresMail(): void
    {
        Mail::to('test@example.com')
            ->subject('Test Subject')
            ->html('<h1>Hello</h1>')
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertSame('test@example.com', $mails[0]['to']);
        $this->assertSame('Test Subject', $mails[0]['subject']);
        $this->assertSame('<h1>Hello</h1>', $mails[0]['body']);
        $this->assertSame('text/html', $mails[0]['content_type']);
    }

    public function testSendWithTextBody(): void
    {
        Mail::to('test@example.com')
            ->subject('Plain Text')
            ->text('Hello World')
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertSame('text/plain', $mails[0]['content_type']);
        $this->assertSame('Hello World', $mails[0]['body']);
    }

    public function testSendWithHtmlBody(): void
    {
        Mail::to('test@example.com')
            ->subject('HTML Email')
            ->html('<p>HTML content</p>')
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertSame('text/html', $mails[0]['content_type']);
    }

    public function testSendWithCc(): void
    {
        Mail::to('primary@example.com')
            ->cc('cc1@example.com')
            ->cc('cc2@example.com')
            ->subject('With CC')
            ->text('Content')
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertContains('cc1@example.com', $mails[0]['cc']);
        $this->assertContains('cc2@example.com', $mails[0]['cc']);
    }

    public function testSendWithBcc(): void
    {
        Mail::to('primary@example.com')
            ->bcc('bcc@example.com')
            ->subject('With BCC')
            ->text('Content')
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertContains('bcc@example.com', $mails[0]['bcc']);
    }

    public function testSendWithReplyTo(): void
    {
        Mail::to('test@example.com')
            ->replyTo('replies@example.com')
            ->subject('Reply To Test')
            ->text('Content')
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertSame('replies@example.com', $mails[0]['reply_to']);
    }

    public function testAssertSentPasses(): void
    {
        Mail::to('test@example.com')
            ->subject('Expected Subject')
            ->text('Content')
            ->send();

        Mail::assertSent('Expected Subject');
        $this->assertTrue(true);
    }

    public function testAssertSentFails(): void
    {
        Mail::to('test@example.com')
            ->subject('Actual Subject')
            ->text('Content')
            ->send();

        $this->expectException(\PHPUnit\Framework\ExpectationFailedException::class);
        Mail::assertSent('Wrong Subject');
    }

    public function testSendMultipleEmails(): void
    {
        Mail::to('user1@example.com')
            ->subject('Email 1')
            ->text('Content 1')
            ->send();

        Mail::to('user2@example.com')
            ->subject('Email 2')
            ->text('Content 2')
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertCount(2, $mails);
        $this->assertSame('user1@example.com', $mails[0]['to']);
        $this->assertSame('user2@example.com', $mails[1]['to']);
    }

    public function testQueueStoresMail(): void
    {
        Mail::to('test@example.com')
            ->subject('Queued Email')
            ->text('Content')
            ->queue();

        $jobs = Queue::getFakedJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('Siro\Core\SendMailJob', $jobs[0]['job']);
        $this->assertSame('test@example.com', $jobs[0]['data']['to']);
    }

    public function testSendLaterStoresMail(): void
    {
        Mail::to('test@example.com')
            ->subject('Delayed Email')
            ->text('Content')
            ->sendLater(3600);

        $jobs = Queue::getFakedJobs();
        $this->assertCount(1, $jobs);
    }

    public function testChainedMethods(): void
    {
        $result = Mail::to('a@b.com')
            ->cc('cc@b.com')
            ->bcc('bcc@b.com')
            ->replyTo('reply@b.com')
            ->subject('Chain Test')
            ->html('<p>Test</p>')
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertCount(1, $mails);
        $this->assertSame('a@b.com', $mails[0]['to']);
        $this->assertSame('Chain Test', $mails[0]['subject']);
    }

    public function testMailHasCorrectStructure(): void
    {
        Mail::to('test@example.com')
            ->subject('Structure Test')
            ->html('<p>Content</p>')
            ->send();

        $mails = Mail::getFakedMails();
        $mail = $mails[0];

        $this->assertArrayHasKey('to', $mail);
        $this->assertArrayHasKey('subject', $mail);
        $this->assertArrayHasKey('body', $mail);
        $this->assertArrayHasKey('content_type', $mail);
        $this->assertArrayHasKey('cc', $mail);
        $this->assertArrayHasKey('bcc', $mail);
        $this->assertArrayHasKey('reply_to', $mail);
        $this->assertArrayHasKey('attachments', $mail);
    }

    public function testHtmlDefaultsToTextPlainIfNotCalled(): void
    {
        Mail::to('test@example.com')
            ->subject('Test')
            ->text('Default body') // must set body since send() requires it
            ->send();

        $mails = Mail::getFakedMails();
        $this->assertSame('text/plain', $mails[0]['content_type']);
    }
}
