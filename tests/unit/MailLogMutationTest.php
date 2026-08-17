<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Env;
use Siro\Core\Mail;

/**
 * Mail send paths: fake + SMTP failure branches.
 */
final class MailLogMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('MAIL_DRIVER=smtp');
        putenv('MAIL_HOST=127.0.0.1');
        putenv('MAIL_PORT=1');
        Mail::reset();
    }

    protected function tearDown(): void
    {
        Mail::reset();
        putenv('MAIL_DRIVER');
        putenv('MAIL_HOST');
        putenv('MAIL_PORT');
        parent::tearDown();
    }

    public function testSendFake(): void
    {
        Mail::fake();
        $ok = Mail::to('a@example.com')->subject('S')->html('<p>hi</p>')->send();
        $this->assertTrue($ok);
        $this->assertNotEmpty(Mail::getFakedMails());
    }

    public function testSendFakeText(): void
    {
        Mail::fake();
        $ok = Mail::to('b@example.com')->subject('T')->text('plain')->send();
        $this->assertTrue($ok);
    }

    public function testSendFakeWithAttach(): void
    {
        Mail::fake();
        $base = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : getcwd();
        $dir = $base . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $dir . '/mail_fake_attach.txt';
        file_put_contents($tmp, 'data');
        $ok = Mail::to('c@example.com')->cc('d@example.com')->subject('A')->html('body')->attach($tmp, 'f.txt')->send();
        $this->assertTrue($ok);
        @unlink($tmp);
    }

    public function testSendFakeReplyTo(): void
    {
        Mail::fake();
        $ok = Mail::to('e@example.com')->replyTo('f@example.com')->subject('R')->text('body')->send();
        $this->assertTrue($ok);
    }

    public function testSendSmtpFailThrows(): void
    {
        try {
            Mail::to('g@example.com')->subject('S')->html('b')->send();
            $this->fail('expected SMTP exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SMTP', $e->getMessage());
        }
    }
}
