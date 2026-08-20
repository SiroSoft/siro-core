<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Model;

/**
 * BelongsToMany extra branches against local MySQL (port 3307, no password).
 */
final class BelongsToManyMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3307,
            'username' => 'root',
            'password' => '',
            'database' => 'siro_test',
            'slow_query_threshold' => 500,
        ]);
        try {
            $pdo = $this->pdo();
            $pdo->exec('CREATE TABLE IF NOT EXISTS bmu_users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS bmu_roles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS bmu_role_user (user_id INT, role_id INT, created_at TEXT, extra TEXT, PRIMARY KEY(user_id, role_id))');
            $pdo->exec("INSERT IGNORE INTO bmu_users (id, name) VALUES (1, 'U1'), (2, 'U2')");
            $pdo->exec("INSERT IGNORE INTO bmu_roles (id, name) VALUES (1, 'admin'), (2, 'editor')");
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL on 127.0.0.1:3307 not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            $pdo = $this->pdo();
            $pdo->exec('DROP TABLE IF EXISTS bmu_users');
            $pdo->exec('DROP TABLE IF EXISTS bmu_roles');
            $pdo->exec('DROP TABLE IF EXISTS bmu_role_user');
        } catch (\Throwable) {
        }
        Database::purgeAll();
        parent::tearDown();
    }

    private function pdo(): PDO
    {
        return new PDO('mysql:host=127.0.0.1;port=3307;dbname=siro_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function testWithPivotGetColumns(): void
    {
        $user = BMUUser::find(1);
        $rel = $user->roles();
        $this->assertNotEmpty($rel->getPivotColumns());
        $this->assertSame(BMURole::class, $rel->getRelatedClass());
        $this->assertSame('bmu_role_user', $rel->getPivotTable());
        $this->assertSame('user_id', $rel->getForeignKey());
        $this->assertSame('role_id', $rel->getRelatedKey());
        $this->assertSame('id', $rel->getLocalKey());
    }

    public function testQueryBuilder(): void
    {
        $user = BMUUser::find(1);
        $user->roles()->attach(1);
        $qb = $user->roles()->query();
        $this->assertInstanceOf(\Siro\Core\DB\QueryBuilder::class, $qb);
        $this->assertNotEmpty($qb->get());
    }

    public function testSyncWithPivotData(): void
    {
        $user = BMUUser::find(1);
        $user->roles()->sync([1, 2]);
        $this->assertCount(2, $user->roles()->get());
        $user->roles()->sync([2]);
        $this->assertCount(1, $user->roles()->get());
    }

    public function testToggleAddAndRemove(): void
    {
        $user = BMUUser::find(1);
        $user->roles()->toggle(1);
        $this->assertCount(1, $user->roles()->get());
        $user->roles()->toggle(1);
        $this->assertCount(0, $user->roles()->get());
    }

    public function testDetachMissingId(): void
    {
        $user = BMUUser::find(1);
        $user->roles()->detach(999);
        $this->assertTrue(true);
    }

    public function testHasFalse(): void
    {
        $user = BMUUser::find(1);
        $this->assertFalse($user->roles()->has(1));
    }

    public function testHasAfterAttach(): void
    {
        $user = BMUUser::find(1);
        $user->roles()->attach(2);
        $this->assertTrue($user->roles()->has(2));
    }

    public function testGetReturnsEmptyWhenNone(): void
    {
        $user = BMUUser::find(2);
        $this->assertCount(0, $user->roles()->get());
    }

    public function testDetachAllEmpty(): void
    {
        $user = BMUUser::find(2);
        $user->roles()->detachAll();
        $this->assertCount(0, $user->roles()->get());
    }
}

final class BMUUser extends Model
{
    protected string $table = 'bmu_users';

    /** @var array<int, string> */
    protected array $fillable = ['name'];

    public function roles(): \Siro\Core\DB\Relations\BelongsToMany
    {
        return $this->belongsToMany(BMURole::class, 'bmu_role_user', 'user_id', 'role_id')->withPivot(['created_at', 'extra']);
    }
}

final class BMURole extends Model
{
    protected string $table = 'bmu_roles';

    /** @var array<int, string> */
    protected array $fillable = ['name'];
}
