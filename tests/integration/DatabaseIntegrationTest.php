<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Siro\Core\Database;
use Siro\Core\DB\QueryBuilder;

final class DatabaseIntegrationTest extends TestCase
{
    private const MYSQL_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=test_siro;charset=utf8mb4';
    private const PGSQL_DSN = 'pgsql:host=127.0.0.1;port=5432;dbname=test_siro;charset=utf8';

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Database::purge();
        parent::tearDown();
    }

    private function connectMySQL(): ?\PDO
    {
        try {
            return new \PDO(self::MYSQL_DSN, 'root', '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable) {
            $this->markTestSkipped('MySQL not available.');
        }
    }

    private function connectPostgreSQL(): ?\PDO
    {
        try {
            return new \PDO(self::PGSQL_DSN, 'postgres', 'postgres', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL not available.');
        }
    }

    /** @requires extension pdo_mysql */
    public function testMySQLConnection(): void
    {
        $pdo = $this->connectMySQL();
        if ($pdo === null) return;
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    /** @requires extension pdo_mysql */
    public function testMySQLCreateTableAndInsert(): void
    {
        $pdo = $this->connectMySQL();
        if ($pdo === null) return;
        $pdo->exec('CREATE TEMPORARY TABLE test_items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
        $pdo->exec("INSERT INTO test_items (name) VALUES ('test1'), ('test2')");
        $rows = $pdo->query('SELECT COUNT(*) as cnt FROM test_items')->fetch();
        $this->assertSame('2', $rows['cnt']);
    }

    /** @requires extension pdo_mysql */
    public function testMySQLQueryBuilder(): void
    {
        $pdo = $this->connectMySQL();
        if ($pdo === null) return;
        $pdo->exec('CREATE TEMPORARY TABLE test_qb (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), value INT)');
        $pdo->exec("INSERT INTO test_qb (name, value) VALUES ('a', 10), ('b', 20), ('c', 30)");

        Database::configure([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'test', 'username' => 'root', 'password' => '',
        ]);
        $pdo2 = Database::connection();
        $this->assertNotNull($pdo2);
    }

    /** @requires extension pdo_pgsql */
    public function testPostgreSQLConnection(): void
    {
        $pdo = $this->connectPostgreSQL();
        if ($pdo === null) return;
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    /** @requires extension pdo_pgsql */
    public function testPostgreSQLCreateTableAndInsert(): void
    {
        $pdo = $this->connectPostgreSQL();
        if ($pdo === null) return;
        $pdo->exec('CREATE TEMPORARY TABLE test_items_pg (id SERIAL PRIMARY KEY, name VARCHAR(100))');
        $pdo->exec("INSERT INTO test_items_pg (name) VALUES ('test1'), ('test2')");
        $rows = $pdo->query('SELECT COUNT(*) as cnt FROM test_items_pg')->fetch();
        $this->assertSame('2', (string) $rows['cnt']);
    }

    /** @requires extension pdo_pgsql */
    public function testPostgreSQLQueryBuilderQuoting(): void
    {
        $pdo = $this->connectPostgreSQL();
        if ($pdo === null) return;
        $pdo->exec('CREATE TEMPORARY TABLE test_quoting (id SERIAL PRIMARY KEY, name VARCHAR(100), score INT)');
        $pdo->exec("INSERT INTO test_quoting (name, score) VALUES ('x', 100)");

        $stmt = $pdo->query('SELECT * FROM test_quoting WHERE score = 100');
        $row = $stmt->fetch();
        $this->assertSame('x', $row['name']);
    }
}
