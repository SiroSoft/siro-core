<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\ApiWhyCommand;
use Siro\Core\Commands\DebugLastCommand;
use Siro\Core\Commands\FixCommand;
use Siro\Core\Env;

/**
 * Covers the diagnostic/guess branches of the Why commands and
 * the FixCommand watcher helpers via direct invocation.
 */
final class DebugWorkflowEdgeTest extends TestCase
{
    private string $basePath;
    private string $tracesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_wfedge_' . uniqid('', true);
        $this->tracesDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'traces';
        mkdir($this->tracesDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->restoreBootstrapEnv();
        Env::reset();
        \Siro\Core\Cache::reset();
        \Siro\Core\Database::purgeAll();
        \Siro\Core\Session::setInstance(null);
        unset($_COOKIE['siro_session']);
        \Siro\Core\Model::resetRelationAccessCount();
        if (is_dir($this->basePath)) {
            $this->rmDir($this->basePath);
        }
        parent::tearDown();
    }

    private function restoreBootstrapEnv(): void
    {
        putenv('APP_KEY=test_app_key_for_encryption_tests_32chars!!');
        $_ENV['APP_KEY'] = 'test_app_key_for_encryption_tests_32chars!!';
        putenv('JWT_SECRET=test_jwt_secret_key_for_unit_tests_only_32chars!!');
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_key_for_unit_tests_only_32chars!!';
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
    }

    private function rmDir(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function writeTrace(string $name, array $data): void
    {
        file_put_contents(
            $this->tracesDir . DIRECTORY_SEPARATOR . $name . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return array<int, string> */
    private function invokeGuessCause(string $class, string $msg, int $status): array
    {
        $cmd = new DebugLastCommand($this->basePath);
        $ref = new \ReflectionClass(DebugLastCommand::class);
        $m = $ref->getMethod('guessCause');
        $m->setAccessible(true);
        /** @var array<int, string> $result */
        $result = $m->invoke($cmd, $class, $msg, $status);
        return $result;
    }

    /** @return array<int, string> */
    private function invokeGuessFix(string $class, string $msg, int $status): array
    {
        $cmd = new DebugLastCommand($this->basePath);
        $ref = new \ReflectionClass(DebugLastCommand::class);
        $m = $ref->getMethod('guessFix');
        $m->setAccessible(true);
        /** @var array<int, string> $result */
        $result = $m->invoke($cmd, $class, $msg, $status, 'traceX');
        return $result;
    }

    // ── DebugLastCommand::guessCause branches ──

    public function testGuessCauseNoSuchTable(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'no such table: users', 500));
    }

    public function testGuessCauseNoSuchColumn(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'no such column: email', 500));
    }

    public function testGuessCauseDeadlock(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'deadlock detected', 500));
    }

    public function testGuessCauseTimeout(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'query timed out', 500));
    }

    public function testGuessCauseNotFound(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'record not found', 404));
    }

    public function testGuessCauseDuplicate(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'duplicate entry for key', 500));
    }

    public function testGuessCauseSyntaxError(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'syntax error in SQL', 500));
    }

    public function testGuessCauseUnauthorized(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'unauthenticated', 401));
        $this->assertNotEmpty($this->invokeGuessCause('', 'x', 401));
    }

    public function testGuessCauseForbidden(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'forbidden', 403));
        $this->assertNotEmpty($this->invokeGuessCause('', 'x', 403));
    }

    public function testGuessCause422(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'validation failed', 422));
    }

    public function testGuessCause500(): void
    {
        $this->assertNotEmpty($this->invokeGuessCause('', 'boom', 500));
    }

    public function testGuessCauseNone(): void
    {
        $this->assertSame([], $this->invokeGuessCause('', 'ok message', 200));
    }

    // ── DebugLastCommand::guessFix branches ──

    public function testGuessFixNoSuchTable(): void
    {
        $fix = $this->invokeGuessFix('', 'no such table: users', 500);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFixDeadlock(): void
    {
        $fix = $this->invokeGuessFix('', 'deadlock detected', 500);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFixTimeout(): void
    {
        $fix = $this->invokeGuessFix('', 'timed out', 500);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFixNotFound(): void
    {
        $fix = $this->invokeGuessFix('', 'does not exist', 404);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFixDuplicate(): void
    {
        $fix = $this->invokeGuessFix('', 'unique constraint violated', 500);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFix401(): void
    {
        $fix = $this->invokeGuessFix('', 'x', 401);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFix403(): void
    {
        $fix = $this->invokeGuessFix('', 'x', 403);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFix422(): void
    {
        $fix = $this->invokeGuessFix('', 'x', 422);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFix500(): void
    {
        $fix = $this->invokeGuessFix('', 'boom', 500);
        $this->assertNotEmpty($fix);
    }

    public function testGuessFixNone(): void
    {
        $this->assertSame([], $this->invokeGuessFix('', 'ok', 200));
    }

    // ── DebugLastCommand invalid trace + response source ──

    public function testWhyInvalidTraceFile(): void
    {
        file_put_contents($this->tracesDir . DIRECTORY_SEPARATOR . 'bad.json', '{ not json');
        ob_start();
        $cmd = new DebugLastCommand($this->basePath);
        $exit = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(1, $exit);
    }

    public function testWhyResponseSourceInline(): void
    {
        $this->writeTrace('wrs', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'time_ms' => 5, 'response_body' => '{"success":true}',
        ]);
        ob_start();
        $cmd = new DebugLastCommand($this->basePath);
        $exit = $cmd->run([]);
        ob_end_clean();
        $this->assertSame(0, $exit);
    }

    // ── FixCommand helpers ──

    public function testFixLastFlag(): void
    {
        // --last sets targetTrace to __last__ → no history → error
        ob_start();
        $cmd = new FixCommand($this->basePath);
        $exit = $cmd->run(['--last']);
        ob_end_clean();
        $this->assertSame(1, $exit);
    }

    public function testFixInvalidTraceFile(): void
    {
        file_put_contents($this->tracesDir . DIRECTORY_SEPARATOR . 'badfix.json', '{ nope');
        ob_start();
        $cmd = new FixCommand($this->basePath);
        $exit = $cmd->run(['badfix']);
        ob_end_clean();
        $this->assertSame(1, $exit);
    }

    public function testFixGetMaxMtime(): void
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'app';
        mkdir($dir, 0777, true);
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'Controller.php', '<?php');
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('getMaxMtime');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        $max = $m->invoke($cmd, [$dir]);
        $this->assertGreaterThan(0, $max);
        // Missing dir → 0
        $this->assertSame(0, $m->invoke($cmd, [$this->basePath . DIRECTORY_SEPARATOR . 'missing']));
    }

    public function testFixGetLastTraceId(): void
    {
        $this->writeTrace('lasttrace', ['method' => 'GET', 'path' => '/x', 'status' => 200]);
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('getLastTraceId');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        $id = $m->invoke($cmd);
        $this->assertSame('lasttrace', $id);
    }

    public function testFixGetLastTraceIdEmpty(): void
    {
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('getLastTraceId');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        $this->assertNull($m->invoke($cmd));
    }

    public function testFixGetLastApiTestCommandField(): void
    {
        $storage = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storage)) {
            mkdir($storage, 0777, true);
        }
        file_put_contents(
            $storage . DIRECTORY_SEPARATOR . 'api-test-history.json',
            json_encode([['command' => 'api:test GET /api/users']])
        );
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('getLastApiTest');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        $result = $m->invoke($cmd);
        $this->assertSame('api:test GET /api/users', $result);
    }

    public function testFixGetLastApiTestNoHistory(): void
    {
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('getLastApiTest');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        $this->assertNull($m->invoke($cmd));
    }

    // ── ApiWhyCommand guess branches ──

    public function testApiWhyGuessBranches(): void
    {
        $cmd = new ApiWhyCommand($this->basePath);
        $ref = new \ReflectionClass(ApiWhyCommand::class);
        foreach (['guessCause', 'guessFix'] as $method) {
            $m = $ref->getMethod($method);
            $m->setAccessible(true);
            $result = $m->invoke($cmd, 'RuntimeException', 'no such table: orders', 500, 'tr1');
            $this->assertNotEmpty($result);
        }
    }

    public function testApiWhyGuessCauseAllBranches(): void
    {
        $cmd = new ApiWhyCommand($this->basePath);
        $ref = new \ReflectionClass(ApiWhyCommand::class);
        $m = $ref->getMethod('guessCause');
        $m->setAccessible(true);
        $cases = [
            ['no such column: email', 500],
            ['deadlock detected', 500],
            ['query timed out', 500],
            ['record not found', 404],
            ['duplicate entry', 500],
            ['syntax error', 500],
            ['unauthenticated', 401],
            ['forbidden', 403],
            ['x', 422],
            ['x', 500],
            ['ok', 200],
        ];
        foreach ($cases as [$msg, $status]) {
            $result = $m->invoke($cmd, '', $msg, $status);
            if ($status === 200) {
                $this->assertSame([], $result);
            } else {
                $this->assertNotEmpty($result, "no cause for: $msg ($status)");
            }
        }
    }

    public function testApiWhyGuessFixAllBranches(): void
    {
        $cmd = new ApiWhyCommand($this->basePath);
        $ref = new \ReflectionClass(ApiWhyCommand::class);
        $m = $ref->getMethod('guessFix');
        $m->setAccessible(true);
        $cases = [
            ['no such table: orders', 500],
            ['deadlock', 500],
            ['timed out', 500],
            ['does not exist', 404],
            ['unique constraint', 500],
            ['x', 401],
            ['x', 403],
            ['x', 422],
            ['x', 500],
            ['ok', 200],
        ];
        foreach ($cases as [$msg, $status]) {
            $result = $m->invoke($cmd, '', $msg, $status, 'tr9');
            if ($status === 200) {
                $this->assertSame([], $result);
            } else {
                $this->assertNotEmpty($result, "no fix for: $msg ($status)");
            }
        }
    }

    public function testApiWhyFindResponseSourceEmpty(): void
    {
        $cmd = new ApiWhyCommand($this->basePath);
        $ref = new \ReflectionClass(ApiWhyCommand::class);
        $m = $ref->getMethod('findResponseSource');
        $m->setAccessible(true);
        $this->assertSame('', $m->invoke($cmd, []));
    }

    public function testApiWhySafeStrBranches(): void
    {
        $cmd = new ApiWhyCommand($this->basePath);
        $ref = new \ReflectionClass(ApiWhyCommand::class);
        $m = $ref->getMethod('safeStr');
        $m->setAccessible(true);
        $this->assertSame('str', $m->invoke($cmd, 'str'));
        $this->assertSame('42', $m->invoke($cmd, 42));
        $this->assertSame('', $m->invoke($cmd, null));
        $this->assertSame('true', $m->invoke($cmd, true));
        $this->assertSame('', $m->invoke($cmd, []));
    }

    public function testDebugLastN1Detection(): void
    {
        \Siro\Core\Model::resetRelationAccessCount();
        $model = new \Siro\Core\Tests\Unit\WorkflowN1Model();
        $model->getRelation('orders');
        $model->getRelation('orders');
        $this->writeTrace('wfn1', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'time_ms' => 5, 'response_body' => '{"success":true}',
        ]);
        ob_start();
        $cmd = new DebugLastCommand($this->basePath);
        $exit = $cmd->run([]);
        $output = ob_get_clean();
        \Siro\Core\Model::resetRelationAccessCount();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('N+1', (string) $output);
    }

    public function testApiWhyN1Detection(): void
    {
        \Siro\Core\Model::resetRelationAccessCount();
        $model = new \Siro\Core\Tests\Unit\WorkflowN1Model();
        $model->getRelation('products');
        $model->getRelation('products');
        $this->writeTrace('wan1', [
            'method' => 'GET', 'path' => '/api/products', 'status' => 200,
            'time_ms' => 5, 'response_body' => '{"success":true}',
        ]);
        ob_start();
        $cmd = new ApiWhyCommand($this->basePath);
        $exit = $cmd->run(['GET', '/api/products']);
        $output = ob_get_clean();
        \Siro\Core\Model::resetRelationAccessCount();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('N+1', (string) $output);
    }

    public function testDebugLastResponseSourceFromData(): void
    {
        $this->writeTrace('wsrc', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'time_ms' => 5,
            'response_body' => '{"_source":"database","success":true}',
        ]);
        ob_start();
        $cmd = new DebugLastCommand($this->basePath);
        $exit = $cmd->run([]);
        $output = ob_get_clean();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('database', (string) $output);
    }

    // ── DbWhyCommand helpers ──

    public function testDbWhySuggestIndexesScan(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('suggestIndexes');
        $m->setAccessible(true);
        $explain = [['type' => 'ALL', 'Extra' => 'Using where', 'rows' => 5000]];
        $suggestions = $m->invoke($cmd, 'SELECT * FROM orders WHERE user_id = 5', $explain);
        $this->assertNotEmpty($suggestions);
        $this->assertStringContainsString('CREATE INDEX', implode(' ', $suggestions));
    }

    public function testDbWhySuggestIndexesNoIssues(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('suggestIndexes');
        $m->setAccessible(true);
        $suggestions = $m->invoke($cmd, 'SELECT 1', null);
        $this->assertSame([], $suggestions);
    }

    public function testDbWhySuggestIndexesFilesort(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('suggestIndexes');
        $m->setAccessible(true);
        $explain = [['detail' => 'USE TEMP B-TREE FOR ORDER BY']];
        $suggestions = $m->invoke($cmd, 'SELECT * FROM orders ORDER BY created_at', $explain);
        $this->assertStringContainsString('filesort', implode(' ', $suggestions));
    }

    public function testDbWhyExtractTableName(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('extractTableName');
        $m->setAccessible(true);
        $this->assertSame('orders', $m->invoke($cmd, 'SELECT * FROM orders WHERE x = 1'));
        $this->assertSame('users', $m->invoke($cmd, 'UPDATE users SET a = 1'));
        $this->assertSame('logs', $m->invoke($cmd, 'INSERT INTO logs (a) VALUES (1)'));
        $this->assertNull($m->invoke($cmd, 'SELECT 1'));
    }

    public function testDbWhyExtractWhereColumns(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('extractWhereColumns');
        $m->setAccessible(true);
        $cols = $m->invoke($cmd, 'SELECT * FROM orders WHERE user_id = ? AND status = :s LIMIT 10');
        $this->assertContains('user_id', $cols);
        $this->assertContains('status', $cols);
    }

    public function testDbWhyColorizeExplainText(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('colorizeExplainText');
        $m->setAccessible(true);
        $this->assertStringContainsString('SCAN', $m->invoke($cmd, 'SCAN orders'));
        $this->assertStringContainsString('TEMP', $m->invoke($cmd, 'USE TEMP'));
        $this->assertStringContainsString('PRIMARY KEY', $m->invoke($cmd, 'SEARCH PRIMARY KEY'));
    }

    public function testDbWhyGetExplainColor(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('getExplainColor');
        $m->setAccessible(true);
        $this->assertStringContainsString('31', $m->invoke($cmd, 'type', 'ALL'));
        $this->assertStringContainsString('32', $m->invoke($cmd, 'type', 'const'));
        $this->assertStringContainsString('31', $m->invoke($cmd, 'rows', '50000'));
        $this->assertStringContainsString('33', $m->invoke($cmd, 'rows', '2000'));
        $this->assertStringContainsString('31', $m->invoke($cmd, 'extra', 'Using temporary'));
    }

    public function testDbWhyListSlowNoTraces(): void
    {
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('listSlowQueries');
        $m->setAccessible(true);
        ob_start();
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $exit = $m->invoke($cmd);
        ob_end_clean();
        $this->assertSame(1, $exit);
    }

    public function testDbWhyListSlowNoSlowQueries(): void
    {
        $this->writeTrace('wslow', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'queries' => [['sql' => 'SELECT 1', 'time_ms' => 5]],
        ]);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('listSlowQueries');
        $m->setAccessible(true);
        ob_start();
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $exit = $m->invoke($cmd);
        ob_end_clean();
        $this->assertSame(0, $exit);
    }

    public function testDbWhyRunExplainWithSqliteDb(): void
    {
        // Create a real SQLite file so runExplain can connect
        $dbDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }
        $dbPath = $dbDir . DIRECTORY_SEPARATOR . 'test.db';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total REAL)');
        $pdo = null;

        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('runExplain');
        $m->setAccessible(true);
        $result = $m->invoke($cmd, 'SELECT * FROM orders WHERE user_id = ?');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testDbWhyDetectDriver(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('detectDriver');
        $m->setAccessible(true);
        $this->assertIsString($m->invoke($cmd));
    }

    public function testDbWhyNoTracesInFind(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('analyzeQuery');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd, ['sql' => 'SELECT * FROM users', 'time_ms' => 10, 'rows' => 5], ['trace_id' => 't1', 'method' => 'GET', 'path' => '/api/users']);
        $output = ob_get_clean();
        $this->assertStringContainsString('Query Analysis', (string) $output);
    }

    public function testDbWhyQueryNotFoundWithTraces(): void
    {
        // Trace exists but the hash doesn't match any query → "Query not found"
        $this->writeTrace('wnotfound', [
            'method' => 'GET', 'path' => '/health', 'status' => 200,
            'queries' => [['sql' => 'SELECT 1', 'time_ms' => 5]],
        ]);
        ob_start();
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $exit = $cmd->run(['ffffffff']);
        $output = ob_get_clean();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Query not found', (string) $output);
    }

    public function testDbWhyAnalyzeWithRealExplainSqlite(): void
    {
        // Create test.db so runExplain works (sqlite driver → detail column)
        $dbDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }
        $dbPath = $dbDir . DIRECTORY_SEPARATOR . 'test.db';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT)');
        $pdo = null;

        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('analyzeQuery');
        $m->setAccessible(true);
        ob_start();
        $m->invoke($cmd, ['sql' => 'SELECT * FROM users WHERE email = ?', 'time_ms' => 10, 'rows' => 5], ['trace_id' => 't1', 'method' => 'GET', 'path' => '/api/users']);
        $output = ob_get_clean();
        $this->assertStringContainsString('Query Analysis', (string) $output);
    }

    public function testDbWhyRunExplainInvalidSqlReturnsNull(): void
    {
        // Invalid SQL → PDO query throws → runExplain returns null
        $dbDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }
        $dbPath = $dbDir . DIRECTORY_SEPARATOR . 'test.db';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE IF NOT EXISTS t (id INTEGER)');
        $pdo = null;

        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('runExplain');
        $m->setAccessible(true);
        $result = $m->invoke($cmd, 'SELEC broken sql @@@');
        $this->assertNull($result);
    }

    public function testDbWhyGetExplainColorKeyBranch(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('getExplainColor');
        $m->setAccessible(true);
        // 'key' branch: empty/null → RED, else GREEN
        $this->assertStringContainsString('31', $m->invoke($cmd, 'key', ''));
        $this->assertStringContainsString('32', $m->invoke($cmd, 'key', 'idx_users_email'));
        // 'type' branch variants
        $this->assertStringContainsString('33', $m->invoke($cmd, 'type', 'index'));
        $this->assertStringContainsString('32', $m->invoke($cmd, 'type', 'eq_ref'));
        $this->assertStringContainsString('90', $m->invoke($cmd, 'type', 'system'));
        // 'extra' branch: only temporary/filesort → RED, else GRAY (90)
        $this->assertStringContainsString('90', $m->invoke($cmd, 'extra', 'Using index'));
        $this->assertStringContainsString('31', $m->invoke($cmd, 'extra', 'Using temporary'));
        // 'rows' low
        $this->assertStringContainsString('32', $m->invoke($cmd, 'rows', '100'));
        // default → GRAY (90)
        $this->assertStringContainsString('90', $m->invoke($cmd, 'other', 'x'));
    }

    public function testDbWhyRunExplainNoDb(): void
    {
        // No test.db and Database::connection() throws → runExplain returns null
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('runExplain');
        $m->setAccessible(true);
        $result = $m->invoke($cmd, 'SELECT 1');
        // Either null (no db) or array (if a global connection exists) — just verify no crash
        $this->assertTrue($result === null || is_array($result));
    }

    public function testDbWhyDetectDriverFallback(): void
    {
        $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
        $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
        $m = $ref->getMethod('detectDriver');
        $m->setAccessible(true);
        $driver = $m->invoke($cmd);
        $this->assertIsString($driver);
    }

    public function testDbWhyDetectDriverWithConfiguredDb(): void
    {
        // With a configured Database connection, detectDriver returns the real driver
        \Siro\Core\Database::purgeAll();
        \Siro\Core\Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        try {
            $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
            $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
            $m = $ref->getMethod('detectDriver');
            $m->setAccessible(true);
            $driver = $m->invoke($cmd);
            $this->assertSame('sqlite', $driver);
        } finally {
            \Siro\Core\Database::purgeAll();
        }
    }

    public function testDbWhyRunExplainWithConfiguredDb(): void
    {
        \Siro\Core\Database::purgeAll();
        \Siro\Core\Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'slow_query_threshold' => 500,
        ]);
        \Siro\Core\Database::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        try {
            $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
            $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
            $m = $ref->getMethod('runExplain');
            $m->setAccessible(true);
            $rows = $m->invoke($cmd, 'SELECT * FROM users WHERE email = ?');
            $this->assertIsArray($rows);
        } finally {
            \Siro\Core\Database::purgeAll();
        }
    }

    public function testDbWhyRunExplainSecondaryConnectionCatch(): void
    {
        // No Database configured; make test.db a DIRECTORY so new PDO throws → catch
        \Siro\Core\Database::purgeAll();
        $storage = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storage)) {
            mkdir($storage, 0777, true);
        }
        $dbPath = $storage . DIRECTORY_SEPARATOR . 'test.db';
        @unlink($dbPath);
        mkdir($dbPath, 0777, true); // directory named test.db → PDO open fails
        try {
            $cmd = new \Siro\Core\Commands\DbWhyCommand($this->basePath);
            $ref = new \ReflectionClass(\Siro\Core\Commands\DbWhyCommand::class);
            $m = $ref->getMethod('runExplain');
            $m->setAccessible(true);
            $result = $m->invoke($cmd, 'SELECT 1');
            $this->assertNull($result);
        } finally {
            @rmdir($dbPath);
        }
    }

    // ── FixCommand watcher loop ──

    private function makeSiroBinary(string $statusLine): string
    {
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'siro',
            "#!/usr/bin/env php\n<?php echo \"Status: {$statusLine}\\n\";\n"
        );
        return $this->basePath;
    }

    public function testFixHandleCodeChangeSuccess(): void
    {
        $this->makeSiroBinary('200 OK');
        $this->writeTrace('fxtrace', ['method' => 'GET', 'path' => '/x', 'status' => 200]);
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('handleCodeChange');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        ob_start();
        $m->invoke($cmd, 'api:test GET /x');
        $output = ob_get_clean();
        $this->assertStringContainsString('Code changed', (string) $output);
        $this->assertStringContainsString('FIX SUCCESSFUL', (string) $output);
    }

    public function testFixHandleCodeChangeFailing(): void
    {
        $this->makeSiroBinary('422 Validation failed');
        $this->writeTrace('fxtrace', ['method' => 'GET', 'path' => '/x', 'status' => 422]);
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('handleCodeChange');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        ob_start();
        $m->invoke($cmd, 'api:test GET /x');
        $output = ob_get_clean();
        $this->assertStringContainsString('still failing', (string) $output);
    }

    public function testFixHandleCodeChangeOtherStatus(): void
    {
        $this->makeSiroBinary('302 Redirect');
        $this->writeTrace('fxtrace', ['method' => 'GET', 'path' => '/x', 'status' => 302]);
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('handleCodeChange');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        ob_start();
        $m->invoke($cmd, 'api:test GET /x');
        $output = ob_get_clean();
        $this->assertStringContainsString('302 Redirect', (string) $output);
    }

    public function testFixHandleCodeChangeNullOutput(): void
    {
        // siro binary missing → shell_exec returns null → no crash
        $this->writeTrace('fxtrace', ['method' => 'GET', 'path' => '/x', 'status' => 200]);
        $ref = new \ReflectionClass(FixCommand::class);
        $m = $ref->getMethod('handleCodeChange');
        $m->setAccessible(true);
        $cmd = new FixCommand($this->basePath);
        ob_start();
        $m->invoke($cmd, 'api:test GET /x');
        ob_end_clean();
        $this->assertTrue(true);
    }

    public function testFixWatcherLoopBounded(): void
    {
        // Set up a project with a siro binary, history, app dir, and a change to detect
        $this->makeSiroBinary('200 OK');
        $appDir = $this->basePath . DIRECTORY_SEPARATOR . 'app';
        mkdir($appDir, 0777, true);
        $file = $appDir . DIRECTORY_SEPARATOR . 'Controller.php';
        file_put_contents($file, '<?php');
        $historyDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($historyDir)) {
            mkdir($historyDir, 0777, true);
        }
        file_put_contents(
            $historyDir . DIRECTORY_SEPARATOR . 'api-test-history.json',
            json_encode([['command' => 'api:test GET /x']])
        );

        // Simulate a code change by touching the file after a delay via a background process
        putenv('SIRO_FIX_MAX_ITERATIONS=3');
        $cmd = new FixCommand($this->basePath);
        // Start a timer to modify the file mid-loop
        $proc = proc_open(
            [PHP_BINARY, '-r', 'sleep(2); touch($argv[1]);', $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        ob_start();
        $exit = $cmd->run([]);
        ob_end_clean();
        proc_close($proc);
        putenv('SIRO_FIX_MAX_ITERATIONS');
        $this->assertSame(0, $exit);
    }
}

namespace Siro\Core\Tests\Unit;

use Siro\Core\Model;

class WorkflowN1Model extends Model
{
    protected string $table = 'workflow_n1';
}
