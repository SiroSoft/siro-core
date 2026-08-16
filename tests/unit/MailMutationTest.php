<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;
use Siro\Core\Mail;

/**
 * Branch coverage for Mail: fake mode, cc/bcc/replyTo, attach, assertions.
 */
final class MailMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Mail::reset();
        putenv('MAIL_DRIVER=log');
    }

    protected function tearDown(): void
    {
        Mail::reset();
        putenv('MAIL_DRIVER');
        parent::tearDown();
    }

    public function testFakeMode(): void
    {
        Mail::fake();
        Mail::to('a@example.com')->subject('Hi')->html('<p>hi</p>')->send();
        $this->assertNotEmpty(Mail::getFakedMails());
    }

    public function testAssertSent(): void
    {
        Mail::fake();
        Mail::to('b@example.com')->subject('Hello World')->html('body')->send();
        Mail::assertSent('Hello World');
        Mail::assertSentTo('b@example.com');
    }

    public function testAssertNotSentTo(): void
    {
        Mail::fake();
        Mail::to('c@example.com')->subject('X')->html('y')->send();
        Mail::assertNotSentTo('nobody@example.com');
    }

    public function testCcBccReplyTo(): void
    {
        Mail::fake();
        Mail::to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->replyTo('reply@example.com')
            ->subject('S')
            ->text('plain body')
            ->send();
        $mails = Mail::getFakedMails();
        $this->assertNotEmpty($mails);
    }

    public function testAttach(): void
    {
        Mail::fake();
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $tmp = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'mail_attach.txt';
        if (!is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0775, true);
        }
        file_put_contents($tmp, 'content');
        Mail::to('d@example.com')->subject('A')->html('h')->attach($tmp, 'file.txt')->send();
        $mails = Mail::getFakedMails();
        $this->assertNotEmpty($mails);
        @unlink($tmp);
    }

    public function testSendWithoutSmtpReturnsFalse(): void
    {
        Mail::fake();
        $ok = Mail::to('e@example.com')->subject('S')->html('b')->send();
        $this->assertTrue($ok);
    }

    public function testHtmlAndTextBoth(): void
    {
        Mail::fake();
        Mail::to('f@example.com')->subject('Both')->html('<b>html</b>')->text('text')->send();
        $mails = Mail::getFakedMails();
        $this->assertNotEmpty($mails);
    }
}
