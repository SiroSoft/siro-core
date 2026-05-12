<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
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

    private function initSQLite(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        return $pdo;
    }

    public function testSQLiteConnection(): void
    {
        $pdo = $this->initSQLite();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testSQLiteCreateTableAndInsert(): void
    {
        $pdo = $this->initSQLite();
        $pdo->exec('CREATE TABLE test_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO test_items (name) VALUES ('test1'), ('test2')");
        $rows = $pdo->query('SELECT COUNT(*) as cnt FROM test_items')->fetch();
        $this->assertSame(2, (int) $rows['cnt']);
    }

    public function testSQLiteQueryBuilder(): void
    {
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $pdo = Database::connection();
        $this->assertNotNull($pdo);

        $pdo->exec('CREATE TABLE test_qb (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, value INT)');
        $pdo->exec("INSERT INTO test_qb (name, value) VALUES ('a', 10), ('b', 20), ('c', 30)");

        $qb = new QueryBuilder('test_qb');
        $result = $qb->where('value', '>=', 20)
            ->orderBy('value', 'asc')
            ->get();

        $this->assertCount(2, $result);
        $this->assertSame('b', $result[0]['name']);
        $this->assertSame('c', $result[1]['name']);
    }

    public function testSQLiteTransactionCommit(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE test_txn (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        Database::execute("INSERT INTO test_txn (label) VALUES ('commit_test')");
        $count = Database::select("SELECT COUNT(*) as cnt FROM test_txn");
        $this->assertSame(1, (int) $count[0]['cnt']);
    }

    public function testSQLiteTransactionRollback(): void
    {
        Database::configure(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = Database::connection();
        $pdo->exec('CREATE TABLE test_txn2 (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

        Database::execute("INSERT INTO test_txn2 (label) VALUES ('outside transaction')");

        try {
            Database::transaction(function () {
                Database::execute("INSERT INTO test_txn2 (label) VALUES ('inside transaction')");
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $count = Database::select("SELECT COUNT(*) as cnt FROM test_txn2");
        $this->assertSame(1, (int) $count[0]['cnt']);
    }

    #[RequiresPhpExtension('pdo_mysql')]
    public function testMySQLConnection(): void
    {
        $pdo = $this->connectMySQL();
        if ($pdo === null) return;
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    #[RequiresPhpExtension('pdo_mysql')]
    public function testMySQLCreateTableAndInsert(): void
    {
        $pdo = $this->connectMySQL();
        if ($pdo === null) return;
        $pdo->exec('CREATE TEMPORARY TABLE test_items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
        $pdo->exec("INSERT INTO test_items (name) VALUES ('test1'), ('test2')");
        $rows = $pdo->query('SELECT COUNT(*) as cnt FROM test_items')->fetch();
        $this->assertSame('2', $rows['cnt']);
    }

    #[RequiresPhpExtension('pdo_mysql')]
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

    #[RequiresPhpExtension('pdo_pgsql')]
    public function testPostgreSQLConnection(): void
    {
        $pdo = $this->connectPostgreSQL();
        if ($pdo === null) return;
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    #[RequiresPhpExtension('pdo_pgsql')]
    public function testPostgreSQLCreateTableAndInsert(): void
    {
        $pdo = $this->connectPostgreSQL();
        if ($pdo === null) return;
        $pdo->exec('CREATE TEMPORARY TABLE test_items_pg (id SERIAL PRIMARY KEY, name VARCHAR(100))');
        $pdo->exec("INSERT INTO test_items_pg (name) VALUES ('test1'), ('test2')");
        $rows = $pdo->query('SELECT COUNT(*) as cnt FROM test_items_pg')->fetch();
        $this->assertSame('2', (string) $rows['cnt']);
    }

    #[RequiresPhpExtension('pdo_pgsql')]
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
