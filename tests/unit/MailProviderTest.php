<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Mail;
use Siro\Core\Mail\MailProvider;
use Siro\Core\Mail\NullMailProvider;
use Siro\Core\Tests\TestCase;

/**
 * Mail provider contract: swappable transports + never-throw trySend().
 */
final class MailProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::reset();
    }

    protected function tearDown(): void
    {
        Mail::reset();
        parent::tearDown();
    }

    public function testNullProviderAlwaysSucceeds(): void
    {
        $provider = new NullMailProvider();

        $this->assertTrue($provider->send(['to' => 'a@example.com']));
    }

    public function testSetProviderOverridesTransport(): void
    {
        $seen = [];
        $stub = new class($seen) implements MailProvider {
            /** @var array<int, array<string, mixed>> */
            public array $seen = [];

            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool
            {
                $this->seen[] = $mail;
                return true;
            }
        };

        Mail::setProvider($stub);
        $this->assertTrue(Mail::to('user@example.com')->text('Hi')->send());
        $this->assertCount(1, $stub->seen);
        $this->assertSame('user@example.com', $stub->seen[0]['to']);
    }

    public function testTrySendReturnsFalseInsteadOfThrowing(): void
    {
        $failing = new class() implements MailProvider {
            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool
            {
                return false;
            }
        };
        Mail::setProvider($failing);

        $this->assertFalse(Mail::to('user@example.com')->text('Hi')->trySend());
    }

    public function testTrySendReturnsFalseForIncompleteMessage(): void
    {
        // No recipient: send() would throw, trySend() returns false.
        $this->assertFalse(Mail::to('')->text('Hi')->trySend());
    }

    public function testSendStillThrowsWhenProviderFails(): void
    {
        $failing = new class() implements MailProvider {
            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool
            {
                return false;
            }
        };
        Mail::setProvider($failing);

        $this->expectException(\RuntimeException::class);
        Mail::to('user@example.com')->text('Hi')->send();
    }

    public function testSmtpProviderNeverThrowsOnRefusedConnection(): void
    {
        // Nothing listens on this port: must return false, not throw.
        \Siro\Core\Env::reset();
        putenv('MAIL_HOST=127.0.0.1');
        putenv('MAIL_PORT=9');
        try {
            $this->assertFalse(Mail::to('user@example.com')->text('Hi')->trySend());
        } finally {
            putenv('MAIL_HOST');
            putenv('MAIL_PORT');
            \Siro\Core\Env::reset();
        }
    }
}
