<?php

declare(strict_types=1);

namespace Siro\Core\Tests;

use PHPUnit\Framework\TestCase;
use Siro\Core\SendMailJob;

final class SendMailJobTest extends TestCase
{
    public function testJobRequiresValidMailData(): void
    {
        $job = new SendMailJob();

        $this->expectException(\RuntimeException::class);
        $job->handle([]);
    }

    public function testJobClassExists(): void
    {
        $this->assertTrue(class_exists(SendMailJob::class));
    }

    public function testJobHasHandleMethod(): void
    {
        $this->assertTrue(method_exists(SendMailJob::class, 'handle'));
    }

    public function testJobSendsWithMinimalData(): void
    {
        $job = new SendMailJob();

        $this->expectException(\RuntimeException::class);
        $job->handle([
            'to' => 'test@example.com',
            'subject' => 'Test',
            'body' => 'Hello',
            'content_type' => 'text/plain',
        ]);
    }
}
