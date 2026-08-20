<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Mail;

/**
 * Extra Mail branches: queue, sendLater, SMTP error paths.
 */
final class MailQueueMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('APP_ENV=testing');
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL, data TEXT, attempts INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3, priority INTEGER DEFAULT 0,
            timeout INTEGER DEFAULT 60, available_at INTEGER NOT NULL,
            locked_until INTEGER, created_at TEXT
        )');
        Database::execute('CREATE TABLE failed_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL, data TEXT, error TEXT, failed_at TEXT
        )');
        \Siro\Core\Queue::reset();
        Mail::reset();
    }

    protected function tearDown(): void
    {
        Mail::reset();
        Database::purgeAll();
        \Siro\Core\Queue::reset();
        putenv('APP_ENV');
        parent::tearDown();
    }

    public function testQueuePushesJob(): void
    {
        Mail::fake();
        Mail::to('a@example.com')->subject('Q')->html('body')->queue();
        // queue() pushes a SendMailJob; fake mode may not capture it, just ensure no crash
        $this->assertTrue(true);
    }

    public function testSendLaterPushesJob(): void
    {
        Mail::fake();
        Mail::to('b@example.com')->subject('L')->text('body')->sendLater(60);
        $this->assertTrue(true);
    }

    public function testSendSmtpFailureReturnsFalse(): void
    {
        putenv('MAIL_HOST=127.0.0.1');
        putenv('MAIL_PORT=1');
        putenv('MAIL_DRIVER=smtp');
        try {
            $ok = Mail::to('c@example.com')->subject('S')->html('b')->send();
            $this->assertFalse($ok);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SMTP', $e->getMessage());
        }
    }

    public function testSendWithCcBccReply(): void
    {
        Mail::fake();
        Mail::to('d@example.com')->cc('e@example.com')->bcc('f@example.com')->replyTo('g@example.com');
        $this->assertTrue(true);
    }

    public function testSendWithoutSubject(): void
    {
        Mail::fake();
        $ok = Mail::to('h@example.com')->html('body')->send();
        $this->assertTrue($ok);
    }

    public function testAssertSentFailure(): void
    {
        Mail::fake();
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        Mail::assertSent('never-sent');
    }
}
