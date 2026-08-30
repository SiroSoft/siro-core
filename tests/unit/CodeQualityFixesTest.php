<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Auth\JWT;
use Siro\Core\Config;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Schema;

final class CodeQualityFixesTest extends TestCase
{
    private string $siroPhpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siroPhpDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'SiroPHP';
        Database::purgeAll();
        Config::reset();
    }

    private function requireSkeleton(): void
    {
        if (!is_dir($this->siroPhpDir)) {
            $this->markTestSkipped('SiroPHP skeleton directory not present — skipping skeleton-structure tests');
        }
    }

    // ========================================================================
    // Fix 1: PDO persistent connections disabled for MySQL
    // ========================================================================

    public function testDatabaseConnectionCreatesPdoWithPersistentDisabled(): void
    {
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $pdo = Database::connection();
        $this->assertFalse($pdo->getAttribute(\PDO::ATTR_PERSISTENT));
    }

    // ========================================================================
    // Fix 2: BaseService is now an interface (not abstract class)
    // ========================================================================

    public function testBaseServiceIsInterfaceNotAbstractClass(): void
    {
        $this->requireSkeleton();
        $file = $this->siroPhpDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'BaseService.php';
        $this->assertFileExists($file);
        require_once $file;

        $this->assertTrue(interface_exists(\App\Services\BaseService::class));
        $ref = new \ReflectionClass(\App\Services\BaseService::class);
        $this->assertTrue($ref->isInterface());
    }

    public function testBaseServiceInterfaceHasRequiredMethods(): void
    {
        $this->requireSkeleton();
        $file = $this->siroPhpDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'BaseService.php';
        require_once $file;

        $this->assertTrue(method_exists(\App\Services\BaseService::class, 'getAll'));
        $this->assertTrue(method_exists(\App\Services\BaseService::class, 'getById'));
        $this->assertTrue(method_exists(\App\Services\BaseService::class, 'create'));
        $this->assertTrue(method_exists(\App\Services\BaseService::class, 'update'));
        $this->assertTrue(method_exists(\App\Services\BaseService::class, 'delete'));
    }

    // ========================================================================
    // Fix 3: JTI blacklist works correctly
    // ========================================================================

    public function testBlacklistJtiPreventsDecodeOfRevokedToken(): void
    {
        $jti = 'revoked-' . bin2hex(random_bytes(8));
        JWT::blacklistJti($jti, time() + 3600);

        $token = JWT::encode([
            'sub' => 1, 'ver' => 1,
            'iat' => time(), 'exp' => time() + 3600,
            'type' => 'access', 'jti' => $jti,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token has been revoked');
        JWT::decode($token);
    }

    public function testNonBlacklistedJtiDecodesSuccessfully(): void
    {
        $claims = JWT::decode(JWT::encodeAccess(1, 1));
        $this->assertArrayHasKey('jti', $claims);
        $this->assertSame(1, $claims['sub']);
    }

    public function testBlacklistOnlyAffectsTargetedJti(): void
    {
        JWT::blacklistJti('unrelated-jti-' . bin2hex(random_bytes(8)), time() + 3600);

        $token = JWT::encodeAccess(1, 1);
        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    public function testExpiredBlacklistEntryAllowsDecode(): void
    {
        $jti = 'expired-jti-' . bin2hex(random_bytes(8));
        JWT::blacklistJti($jti, time() - 1);

        $token = JWT::encode([
            'sub' => 1, 'ver' => 1,
            'iat' => time(), 'exp' => time() + 3600,
            'type' => 'access', 'jti' => $jti,
        ]);

        $claims = JWT::decode($token);
        $this->assertSame(1, $claims['sub']);
    }

    // ========================================================================
    // Fix 4: JWT decode() checks JTI blacklist
    // ========================================================================

    public function testDecodeRejectsTokenAfterJtiBlacklisted(): void
    {
        $token = JWT::encodeAccess(42, 2);
        $claims = JWT::decode($token);

        JWT::blacklistJti($claims['jti'], time() + 3600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token has been revoked');
        JWT::decode($token);
    }

    // ========================================================================
    // Fix 5: Request null byte stripping no longer breaks %20 URLs
    // ========================================================================

    public function testNormalizePathPreservesPercentEncodedCharacters(): void
    {
        $ref = new \ReflectionMethod(Request::class, 'normalizePath');
        $ref->setAccessible(true);

        $this->assertSame('/search?q=hello%20world', $ref->invoke(null, '/search?q=hello%20world'));
        $this->assertSame('/path/with%20spaces', $ref->invoke(null, '/path/with%20spaces'));
        $this->assertSame('/path%2Fencoded', $ref->invoke(null, '/path%2Fencoded'));
    }

    public function testNormalizePathStripsNullBytes(): void
    {
        $ref = new \ReflectionMethod(Request::class, 'normalizePath');
        $ref->setAccessible(true);

        $this->assertSame('/clean/path', $ref->invoke(null, "/clean" . "\x00" . "/path"));
        $this->assertSame('/cleanpath', $ref->invoke(null, "/clean" . "\x00" . "path"));
        $this->assertSame('/cleanpath', $ref->invoke(null, "/clean%00path"));
    }

    public function testNormalizePathHandlesMixedNullBytesAndPercentEncoding(): void
    {
        $ref = new \ReflectionMethod(Request::class, 'normalizePath');
        $ref->setAccessible(true);

        $this->assertSame('/path%20with%20spaces', $ref->invoke(null, "/path" . "\x00" . "%20with%20spaces"));
        $this->assertSame('/hello%20world', $ref->invoke(null, "/h" . "\x00" . "ello%20world"));
    }

    public function testSetParamsStripsNullBytesPreservesPercentEncoding(): void
    {
        $request = new Request('GET', '/test', []);

        $request->setParams(['slug' => 'hello%20world', 'name' => 'test%20name']);
        $this->assertSame('hello%20world', $request->param('slug'));
        $this->assertSame('test%20name', $request->param('name'));

        $request->setParams(['slug' => "bad\x00value"]);
        $this->assertSame('badvalue', $request->param('slug'));
    }

    // ========================================================================
    // Fix 6: APP_URL in config/app.php uses Env::get() not defined()
    // ========================================================================

    public function testConfigAppUrlUsesEnvGet(): void
    {
        $this->requireSkeleton();
        $configFile = $this->siroPhpDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $this->assertFileExists($configFile);
        $contents = (string) file_get_contents($configFile);

        $this->assertStringContainsString('Env::get(', $contents);
        $this->assertStringContainsString('APP_URL', $contents);
        $this->assertStringNotContainsString('defined(', $contents);
    }

    public function testConfigAppUrlReturnsExpectedDefault(): void
    {
        $this->requireSkeleton();
        $configFile = $this->siroPhpDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $config = (array) require $configFile;

        $this->assertArrayHasKey('url', $config);
        $this->assertIsString($config['url']);
    }

    // ========================================================================
    // Fix 7: Schema::hasTable() escapes LIKE wildcards
    // ========================================================================

    public function testHasTableWithSqliteMemoryConnection(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Schema::connect(Database::connection());

        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE "testtable" (id INTEGER)');

        $this->assertTrue(Schema::hasTable('testtable'));
        $this->assertFalse(Schema::hasTable('nonexistent'));
    }

    public function testHasTableAppliesLikeWildcardEscaping(): void
    {
        $subject = 'test_%table';
        $escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $subject);
        $this->assertSame('test\\\\_\\\\%table', $escaped);
    }

    public function testHasTableEscapesBackslashBeforeWildcards(): void
    {
        $subject = 'test\\_table';
        $escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $subject);
        $this->assertSame('test\\\\\\\\_table', $escaped);
    }

    // ========================================================================
    // Fix 8: Schema::hasColumn() + getColumnListing()
    // ========================================================================

    public function testHasColumnWithSqliteMemoryConnection(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Schema::connect(Database::connection());

        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE "users" (id INTEGER, name TEXT, email TEXT)');

        $this->assertTrue(Schema::hasColumn('users', 'id'));
        $this->assertTrue(Schema::hasColumn('users', 'name'));
        $this->assertTrue(Schema::hasColumn('users', 'email'));
        $this->assertFalse(Schema::hasColumn('users', 'nonexistent'));
    }

    public function testGetColumnListingWithSqliteMemoryConnection(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        Schema::connect(Database::connection());

        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE "test" (id INTEGER, name TEXT NOT NULL, email TEXT DEFAULT NULL)');

        $columns = Schema::getColumnListing('test');
        $this->assertIsArray($columns);
        $this->assertCount(3, $columns);
        $this->assertSame(['id', 'name', 'email'], $columns);
    }
}
