<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Seeder;

/**
 * Siro\Core\Seeder: insertIgnore() only swallows duplicate-key
 * errors; foreign-key violations must throw.
 */
final class SeederTest extends TestCase
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
        // SQLite enforces FKs only with the pragma on.
        Database::connection()->exec('PRAGMA foreign_keys = ON');
        Database::execute('CREATE TABLE seed_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL)');
        Database::execute('CREATE TABLE seed_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id ' .
            'INTEGER NOT NULL REFERENCES seed_users(id), title ' . 'TEXT)');
    }

    protected function tearDown(): void
    {
        Database::purgeAll();
        parent::tearDown();
    }

    public function testInsertIgnoreSkipsDuplicate(): void
    {
        (new SeedTestUserSeeder())->run();
        $this->assertSame(1, Database::table('seed_users')->count());
    }

    public function testInsertIgnoreThrowsOnForeignKeyViolation(): void
    {
        $this->expectException(\PDOException::class);
        (new SeedTestFkSeeder())->run();
    }

    public function testCallRunsAnotherSeeder(): void
    {
        (new SeedTestCallerSeeder())->run();
        $this->assertSame(1, Database::table('seed_users')->count());
    }

    public function testInsertReturnsId(): void
    {
        $seeder = new SeedTestInsertSeeder();
        $seeder->run();
        $this->assertGreaterThan(0, $seeder->insertedId);
        $this->assertSame(1, Database::table('seed_users')->count());
    }
}
