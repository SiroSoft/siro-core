<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\Idempotency;
use Siro\Core\Middleware\IdempotencyMiddleware;

/**
 * Idempotency Unit Tests
 */
final class IdempotencyTest extends TestCase
{
    public function testIdempotencyClassExists(): void
    {
        $this->assertTrue(class_exists(Idempotency::class));
    }

    public function testIdempotencyHasSetKey(): void
    {
        $this->assertTrue(method_exists(Idempotency::class, 'setKey'));
    }

    public function testIdempotencyHasIsDuplicate(): void
    {
        $this->assertTrue(method_exists(Idempotency::class, 'isDuplicate'));
    }

    public function testIdempotencyHasStoreResponse(): void
    {
        $this->assertTrue(method_exists(Idempotency::class, 'storeResponse'));
    }

    public function testIdempotencyHasGetStoredResponse(): void
    {
        $this->assertTrue(method_exists(Idempotency::class, 'getStoredResponse'));
    }

    public function testIdempotencyHasCreateTable(): void
    {
        $this->assertTrue(method_exists(Idempotency::class, 'createTable'));
    }

    public function testIdempotencyHasCleanup(): void
    {
        $this->assertTrue(method_exists(Idempotency::class, 'cleanup'));
    }

    public function testIdempotencyMiddlewareExists(): void
    {
        $this->assertTrue(class_exists(IdempotencyMiddleware::class));
    }

    public function testIdempotencyMiddlewareHasHandle(): void
    {
        $this->assertTrue(method_exists(IdempotencyMiddleware::class, 'handle'));
    }

    public function testIdempotencyCanBeInstantiated(): void
    {
        $idempotency = new Idempotency();
        $this->assertInstanceOf(Idempotency::class, $idempotency);
    }

    public function testIdempotencyWithCustomTtl(): void
    {
        $idempotency = new Idempotency(3600);
        $this->assertInstanceOf(Idempotency::class, $idempotency);
    }

    public function testIdempotencySetKeyWithEmptyKey(): void
    {
        $idempotency = new Idempotency();
        $idempotency->setKey('', 0, 'POST');
        $this->assertFalse($idempotency->isDuplicate());
    }

    public function testIdempotencyClear(): void
    {
        $idempotency = new Idempotency();
        $idempotency->clear();
        $this->assertFalse($idempotency->isDuplicate());
        $this->assertNull($idempotency->getStoredResponse());
    }
}