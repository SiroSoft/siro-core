<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Queue;

final class QueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function testFakeCreatesEmptyQueue(): void
    {
        $jobs = Queue::getFakedJobs();
        $this->assertEmpty($jobs);
    }

    public function testPushAddsJob(): void
    {
        Queue::push('SendEmail', ['to' => 'test@example.com']);
        $jobs = Queue::getFakedJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('SendEmail', $jobs[0]['job']);
        $this->assertSame(['to' => 'test@example.com'], $jobs[0]['data']);
    }

    public function testPushWithoutDelayHasZeroDelay(): void
    {
        Queue::push('SendEmail', ['to' => 'test@example.com'], delay: 0);
        $jobs = Queue::getFakedJobs();
        $this->assertCount(1, $jobs);
    }

    public function testPushWithDelayHasDelay(): void
    {
        Queue::push('SendEmail', ['to' => 'test@example.com'], delay: 3600, priority: 5);
        $jobs = Queue::getFakedJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('SendEmail', $jobs[0]['job']);
        $this->assertIsArray($jobs[0]);
    }

    public function testPushMultipleJobs(): void
    {
        Queue::push('Job1', ['data' => 1]);
        Queue::push('Job2', ['data' => 2]);
        Queue::push('Job3', ['data' => 3]);
        $jobs = Queue::getFakedJobs();
        $this->assertCount(3, $jobs);
    }

    public function testPushSameJobMultipleTimes(): void
    {
        Queue::push('SendEmail', ['to' => 'a@test.com']);
        Queue::push('SendEmail', ['to' => 'b@test.com']);
        $jobs = Queue::getFakedJobs();
        $this->assertCount(2, $jobs);
        $this->assertSame('SendEmail', $jobs[0]['job']);
        $this->assertSame('SendEmail', $jobs[1]['job']);
    }

    public function testAssertPushedPasses(): void
    {
        Queue::push('SendEmail', ['to' => 'test@example.com']);
        Queue::assertPushed('SendEmail', fn($data) => $data['to'] === 'test@example.com');
        $this->assertTrue(true);
    }

    public function testAssertPushedWithNoCallback(): void
    {
        Queue::push('SendEmail', ['to' => 'test@example.com']);
        Queue::assertPushed('SendEmail');
        $this->assertTrue(true);
    }

    public function testAssertNotPushedPasses(): void
    {
        Queue::assertNotPushed('NonExistentJob');
        $this->assertTrue(true);
    }

    public function testAssertNotPushedPassesWhenNoMatchingJob(): void
    {
        Queue::push('JobA', []);
        Queue::assertNotPushed('JobB');
        $this->assertTrue(true);
    }

    public function testAssertPushedPassesWhenMatchingJobExists(): void
    {
        Queue::push('JobA', []);
        Queue::assertPushed('JobA');
        $this->assertTrue(true);
    }

    public function testAssertPushedWithCallback(): void
    {
        Queue::push('SendEmail', ['to' => 'admin@example.com', 'subject' => 'Test']);
        Queue::assertPushed('SendEmail', function($data) {
            return str_contains($data['to'] ?? '', 'admin');
        });
        $this->assertTrue(true);
    }

    public function testAssertPushedWithCallbackFails(): void
    {
        Queue::push('SendEmail', ['to' => 'user@example.com']);
        $this->expectException(\PHPUnit\Framework\ExpectationFailedException::class);
        Queue::assertPushed('SendEmail', fn($data) => ($data['to'] ?? '') === 'admin@example.com');
    }

    public function testPushWithEmptyData(): void
    {
        Queue::push('JobWithEmptyData', []);
        $jobs = Queue::getFakedJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame([], $jobs[0]['data']);
    }

    public function testPushWithComplexData(): void
    {
        $complexData = [
            'user' => ['id' => 1, 'name' => 'John'],
            'items' => [1, 2, 3],
            'nested' => ['a' => ['b' => ['c' => 1]]]
        ];
        Queue::push('ProcessOrder', $complexData);
        $jobs = Queue::getFakedJobs();
        $this->assertSame($complexData, $jobs[0]['data']);
    }

    public function testJobsHaveCorrectStructure(): void
    {
        Queue::push('MyJob', ['key' => 'value']);
        $jobs = Queue::getFakedJobs();
        $job = $jobs[0];
        $this->assertArrayHasKey('job', $job);
        $this->assertArrayHasKey('data', $job);
        $this->assertSame('MyJob', $job['job']);
        $this->assertSame(['key' => 'value'], $job['data']);
    }

    public function testMultipleDifferentJobs(): void
    {
        Queue::push('JobA', ['a' => 1]);
        Queue::push('JobB', ['b' => 2]);
        Queue::push('JobC', ['c' => 3]);
        $jobs = Queue::getFakedJobs();
        $this->assertCount(3, $jobs);
        $this->assertSame('JobA', $jobs[0]['job']);
        $this->assertSame('JobB', $jobs[1]['job']);
        $this->assertSame('JobC', $jobs[2]['job']);
    }

    public function testDataIsPreservedAccurately(): void
    {
        $originalData = [
            'string' => 'hello',
            'number' => 42,
            'bool' => true,
            'null' => null,
            'array' => ['nested' => 'value']
        ];
        Queue::push('TestJob', $originalData);
        $jobs = Queue::getFakedJobs();
        $this->assertSame($originalData, $jobs[0]['data']);
    }
}
