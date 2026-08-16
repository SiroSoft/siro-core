<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Auth\ApiKey: create/validate/revoke/scopes against SQLite.
 */
final class ApiKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        ApiKey::createTable();
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testCreateReturnsToken(): void
    {
        $result = ApiKey::create('TestKey', 'read', 1);
        $this->assertNotEmpty($result['token']);
        $this->assertSame('TestKey', $result['name']);
        $this->assertSame('read', $result['scopes']);
    }

    public function testValidateValidToken(): void
    {
        $result = ApiKey::create('Key1', 'read,write', 1);
        $key = ApiKey::validate($result['token']);
        $this->assertNotNull($key);
        $this->assertSame('Key1', $key['name']);
    }

    public function testValidateInvalidToken(): void
    {
        $this->assertNull(ApiKey::validate('invalid-token'));
    }

    public function testHasScope(): void
    {
        $result = ApiKey::create('Key2', 'read,write', 1);
        $this->assertTrue(ApiKey::hasScope($result['token'], 'write'));
        $this->assertTrue(ApiKey::hasScope($result['token'], 'read'));
        $this->assertFalse(ApiKey::hasScope($result['token'], 'admin'));
    }

    public function testRevoke(): void
    {
        $result = ApiKey::create('Key3', 'read', 1);
        $this->assertNotNull(ApiKey::validate($result['token']));
        ApiKey::revoke($result['token']);
        $this->assertNull(ApiKey::validate($result['token']));
    }

    public function testRevokeAllForUser(): void
    {
        ApiKey::create('K1', 'read', 1);
        ApiKey::create('K2', 'read', 1);
        ApiKey::create('K3', 'read', 2);
        ApiKey::revokeAllForUser(1);
        $keys = ApiKey::listForUser(1);
        $this->assertCount(0, $keys);
        $keys2 = ApiKey::listForUser(2);
        $this->assertCount(1, $keys2);
    }

    public function testListForUser(): void
    {
        ApiKey::create('A', 'read', 1);
        ApiKey::create('B', 'read', 1);
        $keys = ApiKey::listForUser(1);
        $this->assertCount(2, $keys);
    }
}
