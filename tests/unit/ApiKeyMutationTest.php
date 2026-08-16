<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\ApiKey;
use Siro\Core\Database;
use Siro\Core\Env;

/**
 * Strong-assertion tests targeting mutations on Auth\ApiKey.
 * Verifies DB state, expiry math, scopes normalization, and return types.
 */
final class ApiKeyMutationTest extends TestCase
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

    public function testCreateDefaultHasNoExpiry(): void
    {
        $result = ApiKey::create('Key', 'read');
        $this->assertSame(null, $result['expires_at']);
        $row = Database::select(
            'SELECT expires_at FROM api_keys WHERE name = ?',
            ['Key']
        );
        $this->assertSame(0, (int) $row[0]['expires_at']);
    }

    public function testCreateWithExpiryComputesExactDate(): void
    {
        $before = time();
        $result = ApiKey::create('Key', 'read', null, 2);
        $after = time();

        $expected = strtotime($result['created_at']) + 2 * 86400;
        $this->assertSame($expected, strtotime((string) $result['expires_at']));

        $row = Database::select(
            'SELECT created_at, expires_at FROM api_keys WHERE name = ?',
            ['Key']
        );
        $createdAt = (int) $row[0]['created_at'];
        $expiresAt = (int) $row[0]['expires_at'];
        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
        $this->assertSame($createdAt + 2 * 86400, $expiresAt);
        $this->assertGreaterThan(0, $expiresAt);
    }

    public function testCreateNormalizesScopes(): void
    {
        $result = ApiKey::create('Key', '  Read , WRITE  ', 1);
        $this->assertSame('read , write', $result['scopes']);
        $result2 = ApiKey::create('Key2', 'READ,WRITE', 1);
        $this->assertSame('read,write', $result2['scopes']);
    }

    public function testCreateWithNullUserIdStoresZero(): void
    {
        ApiKey::create('Key', 'read', null);
        $row = Database::select(
            'SELECT user_id FROM api_keys WHERE name = ?',
            ['Key']
        );
        $this->assertSame(0, (int) $row[0]['user_id']);
    }

    public function testValidateReturnsFullStructure(): void
    {
        $result = ApiKey::create('Key', 'read', 7);
        $key = ApiKey::validate($result['token']);
        $this->assertNotNull($key);
        $this->assertArrayHasKey('id', $key);
        $this->assertArrayHasKey('name', $key);
        $this->assertArrayHasKey('scopes', $key);
        $this->assertArrayHasKey('user_id', $key);
        $this->assertArrayHasKey('created_at', $key);
        $this->assertArrayHasKey('expires_at', $key);
        $this->assertSame(7, $key['user_id']);
        $this->assertSame('read', $key['scopes']);
        $this->assertSame(null, $key['expires_at']);
        $this->assertIsInt($key['id']);
    }

    public function testValidateUpdatesLastUsedAt(): void
    {
        $result = ApiKey::create('Key', 'read', 1);
        ApiKey::validate($result['token']);
        $row = Database::select(
            'SELECT last_used_at FROM api_keys WHERE name = ?',
            ['Key']
        );
        $this->assertNotEmpty($row[0]['last_used_at']);
    }

    public function testValidateExpiredKeyReturnsNull(): void
    {
        $result = ApiKey::create('Key', 'read', 1);
        $tokenHash = hash('sha256', $result['token']);
        Database::execute(
            'UPDATE api_keys SET expires_at = ? WHERE token_hash = ?',
            [time() - 100, $tokenHash]
        );
        $this->assertNull(ApiKey::validate($result['token']));
    }

    public function testValidateFutureExpiryIsValid(): void
    {
        $result = ApiKey::create('Key', 'read', 1);
        $tokenHash = hash('sha256', $result['token']);
        Database::execute(
            'UPDATE api_keys SET expires_at = ? WHERE token_hash = ?',
            [time() + 5000, $tokenHash]
        );
        $key = ApiKey::validate($result['token']);
        $this->assertNotNull($key);
        $this->assertStringContainsString(':', (string) $key['expires_at']);
    }

    public function testValidateLegacyKeyMigratesToBcrypt(): void
    {
        $token = 'legacy-token-value-1234';
        $tokenHash = hash('sha256', $token);
        Database::execute(
            'INSERT INTO api_keys (name, token_hash, token_bcrypt, scopes, user_id, created_at, expires_at, last_used_at)
             VALUES (?, ?, NULL, ?, ?, ?, 0, NULL)',
            ['Legacy', $tokenHash, 'read', 1, time()]
        );

        $key = ApiKey::validate($token);
        $this->assertNotNull($key);
        $this->assertSame('Legacy', $key['name']);

        $row = Database::select(
            'SELECT token_bcrypt FROM api_keys WHERE token_hash = ?',
            [$tokenHash]
        );
        $this->assertNotEmpty($row[0]['token_bcrypt']);
        $this->assertTrue(password_verify($token, (string) $row[0]['token_bcrypt']));
    }

    public function testRevokeReturnsBool(): void
    {
        $result = ApiKey::create('Key', 'read', 1);
        $this->assertTrue(ApiKey::revoke($result['token']));
        $this->assertFalse(ApiKey::revoke($result['token']));
        $this->assertFalse(ApiKey::revoke('nonexistent-token'));
    }

    public function testRevokeAllForUserReturnsCount(): void
    {
        ApiKey::create('K1', 'read', 1);
        ApiKey::create('K2', 'read', 1);
        $count = ApiKey::revokeAllForUser(1);
        $this->assertSame(2, $count);
        $this->assertSame(0, ApiKey::revokeAllForUser(1));
    }

    public function testListForUserMarksExpired(): void
    {
        $result = ApiKey::create('Expired', 'read', 1);
        $tokenHash = hash('sha256', $result['token']);
        Database::execute(
            'UPDATE api_keys SET expires_at = ? WHERE token_hash = ?',
            [time() - 50, $tokenHash]
        );
        ApiKey::create('Active', 'read', 1);

        $keys = ApiKey::listForUser(1);
        $byName = [];
        foreach ($keys as $key) {
            $byName[$key['name']] = $key;
        }
        $this->assertTrue($byName['Expired']['is_expired']);
        $this->assertFalse($byName['Active']['is_expired']);
    }

    public function testListForUserNonExpiredFields(): void
    {
        $result = ApiKey::create('Active', 'read,write', 1);
        $keys = ApiKey::listForUser(1);
        $this->assertCount(1, $keys);
        $row = $keys[0];
        $this->assertSame('Active', $row['name']);
        $this->assertSame('read,write', $row['scopes']);
        $this->assertSame(false, $row['is_expired']);
    }

    public function testHasScopeAdminShortCircuits(): void
    {
        $result = ApiKey::create('Admin', 'admin', 1);
        $this->assertTrue(ApiKey::hasScope($result['token'], 'write'));
        $this->assertTrue(ApiKey::hasScope($result['token'], 'admin'));
        $this->assertTrue(ApiKey::hasScope($result['token'], 'read'));
    }

    public function testHasScopeIsCaseInsensitive(): void
    {
        $result = ApiKey::create('Key', 'Read,Write', 1);
        $this->assertTrue(ApiKey::hasScope($result['token'], 'read'));
        $this->assertTrue(ApiKey::hasScope($result['token'], 'WRITE'));
    }

    public function testHasScopeTrimsSpaces(): void
    {
        $result = ApiKey::create('Key', ' read ,  write ', 1);
        $this->assertTrue(ApiKey::hasScope($result['token'], 'write'));
        $this->assertFalse(ApiKey::hasScope($result['token'], 'admin'));
    }

    public function testTokenFormatIsDoubleHex(): void
    {
        $result = ApiKey::create('Key', 'read', 1);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{32}-[0-9a-f]{32}$/',
            $result['token']
        );
    }
}
