<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use Siro\Core\Tests\TestCase;
use Siro\Core\Database;

final class DatabaseIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function testConnectionReturnsPdo(): void
    {
        $pdo = Database::connection();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testQueryExecutesSuccessfully(): void
    {
        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE test_items (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO test_items (name) VALUES ('item1')");
        $pdo->exec("INSERT INTO test_items (name) VALUES ('item2')");

        $stmt = $pdo->query('SELECT COUNT(*) FROM test_items');
        $this->assertEquals(2, (int) $stmt->fetchColumn());
    }

    public function testMultipleConnections(): void
    {
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'secondary');

        $default = Database::connection();
        $secondary = Database::connection('secondary');

        $this->assertInstanceOf(\PDO::class, $default);
        $this->assertInstanceOf(\PDO::class, $secondary);
        $this->assertNotSame($default, $secondary);
    }

    public function testConfigureAndSwitchDefault(): void
    {
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'app_',
        ], 'app');

        Database::default('app');
        $pdo = Database::connection();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }
}
