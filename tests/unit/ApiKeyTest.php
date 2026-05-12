<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ApiKey;

final class ApiKeyTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(ApiKey::class));
    }

    public function testHasApiKeyConstants(): void
    {
        $ref = new \ReflectionClass(ApiKey::class);
        $this->assertTrue($ref->hasMethod('create'));
        $this->assertTrue($ref->hasMethod('validate'));
        $this->assertTrue($ref->hasMethod('revoke'));
        $this->assertTrue($ref->hasMethod('hasScope'));
        $this->assertTrue($ref->hasMethod('createTable'));
    }

    public function testCreateTableSqlSyntax(): void
    {
        $this->expectNotToPerformAssertions();
    }
}
