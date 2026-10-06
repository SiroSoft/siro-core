<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Container;
use Siro\Core\Inject;
use Siro\Core\Tests\TestCase;

final class ContainerInjectTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
    }

    public function testInjectScalarFromBindValue(): void
    {
        $this->container->bindValue('config.jwt.ttl', 7200);
        $svc = $this->container->make(InjectTtlService::class);

        $this->assertInstanceOf(InjectTtlService::class, $svc);
        $this->assertSame(7200, $svc->ttl);
    }

    public function testInjectFallsBackToDefaultWhenMissing(): void
    {
        $svc = $this->container->make(InjectTtlService::class);

        $this->assertSame(3600, $svc->ttl);
    }

    public function testInjectStringFromEnv(): void
    {
        putenv('SERVICES_PAYMENT_KEY=sk-test-123');
        $_ENV['SERVICES_PAYMENT_KEY'] = 'sk-test-123';
        try {
            $svc = $this->container->make(InjectKeyService::class);
            $this->assertSame('sk-test-123', $svc->apiKey);
        } finally {
            putenv('SERVICES_PAYMENT_KEY');
            unset($_ENV['SERVICES_PAYMENT_KEY']);
            \Siro\Core\Env::reset();
        }
    }

    public function testInjectIntCoercedFromEnv(): void
    {
        putenv('CONFIG_JWT_TTL=900');
        $_ENV['CONFIG_JWT_TTL'] = '900';
        try {
            $svc = $this->container->make(InjectTtlService::class);
            $this->assertSame(900, $svc->ttl);
        } finally {
            putenv('CONFIG_JWT_TTL');
            unset($_ENV['CONFIG_JWT_TTL']);
            \Siro\Core\Env::reset();
        }
    }

    public function testInjectTypeMismatchThrows(): void
    {
        $this->container->bindValue('config.jwt.ttl', ['not' => 'an-int']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot inject/');
        $this->container->make(InjectTtlService::class);
    }

    public function testInjectFromBindingClosure(): void
    {
        $this->container->bind('config.jwt.ttl', fn (): int => 123);
        $svc = $this->container->make(InjectTtlService::class);
        $this->assertSame(123, $svc->ttl);
    }

    public function testClearRemovesBoundValues(): void
    {
        $this->container->bindValue('config.jwt.ttl', 7200);
        $this->container->clear();
        $svc = $this->container->make(InjectTtlService::class);
        $this->assertSame(3600, $svc->ttl);
    }
}

final class InjectTtlService
{
    public function __construct(
        #[Inject('config.jwt.ttl')] public readonly int $ttl = 3600,
    ) {
    }
}

final class InjectKeyService
{
    public function __construct(
        #[Inject('services.payment.key')] public readonly string $apiKey = '',
    ) {
    }
}
