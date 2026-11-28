<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Siro\Core\Config;
use Siro\Core\Database;
use Siro\Core\Env;
use Siro\Core\Http;
use Siro\Core\Queue;
use Siro\Core\Webhook\WebhookDeliveryJob;
use Siro\Core\Webhook\WebhookDispatcher;
use Siro\Core\Webhook\WebhookSigner;

/**
 * End-to-end proof for G5 webhooks:
 *  - dispatcher queues signed deliveries through the real Queue;
 *  - a real local receiver (`php -S` + webhook-receiver.php) gets the
 *    event and verifies the signature;
 *  - a failing receiver is retried with backoff and dead-letters after
 *    max_attempts (Queue's own retry machinery — the job only throws).
 *
 * @covers \Siro\Core\Webhook\WebhookDispatcher
 * @covers \Siro\Core\Webhook\WebhookDeliveryJob
 */
final class WebhookDeliveryTest extends TestCase
{
    private const string SECRET = 'whsec_delivery_test';

    /** @var resource|null */
    private $serverProcess = null;

    private string $logFile = '';

    private string $dbFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Env::reset();
        putenv('QUEUE_DRIVER=db');
        $_ENV['QUEUE_DRIVER'] = 'db';
        Env::reset();
        Database::purgeAll();
        Database::configure([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'charset' => 'utf8mb4',
            'slow_query_threshold' => 500,
        ]);
        Database::execute('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL,
            data TEXT,
            attempts INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3,
            priority INTEGER DEFAULT 0,
            timeout INTEGER DEFAULT 60,
            available_at INTEGER NOT NULL,
            locked_until INTEGER,
            created_at TEXT
        )');
        Database::execute('CREATE TABLE failed_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL,
            data TEXT,
            error TEXT,
            failed_at TEXT
        )');
        Queue::reset();
        Config::reset();
        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_webhook_' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        if ($this->dbFile !== '' && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }
        Http::timeout(30);
        Database::purgeAll();
        Queue::reset();
        Config::reset();
        putenv('QUEUE_DRIVER');
        unset($_ENV['QUEUE_DRIVER']);
        parent::tearDown();
    }

    public function testDispatcherQueuesDeliveryViaFakeQueue(): void
    {
        Queue::fake();
        $dispatcher = new WebhookDispatcher([
            'endpoints' => [
                ['url' => 'https://example.com/hook', 'secret' => self::SECRET, 'events' => ['order.created']],
            ],
        ]);

        $queued = $dispatcher->dispatch('order.created', ['order_id' => 7]);

        $this->assertSame(1, $queued);
        Queue::assertPushed(WebhookDeliveryJob::class, static function (array $data): bool {
            return $data['url'] === 'https://example.com/hook'
                && $data['event'] === 'order.created'
                && $data['payload'] === ['order_id' => 7]
                && $data['secret'] === WebhookDeliveryTest::SECRET;
        });
    }

    public function testDispatcherFiltersEndpointsByEvent(): void
    {
        Queue::fake();
        $dispatcher = new WebhookDispatcher([
            'endpoints' => [
                ['url' => 'https://example.com/orders', 'secret' => self::SECRET, 'events' => ['order.created']],
                ['url' => 'https://example.com/users', 'secret' => self::SECRET, 'events' => ['user.registered']],
            ],
        ]);

        $queued = $dispatcher->dispatch('order.created', ['order_id' => 1]);

        $this->assertSame(1, $queued);
        Queue::assertPushed(
            WebhookDeliveryJob::class,
            static fn (array $data): bool => $data['url'] === 'https://example.com/orders'
        );
        Queue::assertNotPushed('other-job');
        $jobs = Queue::getFakedJobs();
        $this->assertCount(1, $jobs);
    }

    public function testDispatcherEndpointWithNoEventsReceivesAll(): void
    {
        Queue::fake();
        $dispatcher = new WebhookDispatcher([
            'endpoints' => [
                ['url' => 'https://example.com/all', 'secret' => self::SECRET],
            ],
        ]);

        $this->assertSame(1, $dispatcher->dispatch('order.created', []));
        $this->assertSame(1, $dispatcher->dispatch('anything.else', []));
        $this->assertCount(2, Queue::getFakedJobs());
    }

    public function testDispatcherAdHocUrl(): void
    {
        Queue::fake();
        $dispatcher = new WebhookDispatcher(['default_secret' => self::SECRET]);

        $queued = $dispatcher->dispatch('order.created', ['order_id' => 2], 'https://example.com/adhoc');

        $this->assertSame(1, $queued);
        Queue::assertPushed(
            WebhookDeliveryJob::class,
            static fn (array $data): bool => $data['url'] === 'https://example.com/adhoc'
        );
    }

    public function testDispatcherRejectsInvalidUrl(): void
    {
        $dispatcher = new WebhookDispatcher(['default_secret' => self::SECRET]);
        $this->expectException(InvalidArgumentException::class);
        $dispatcher->dispatch('order.created', [], 'ftp://example.com/hook');
    }

    public function testDispatcherRejectsEmptySecret(): void
    {
        $dispatcher = new WebhookDispatcher([
            'endpoints' => [['url' => 'https://example.com/hook', 'events' => ['order.created']]],
        ]);
        $this->expectException(InvalidArgumentException::class);
        $dispatcher->dispatch('order.created', []);
    }

    public function testDeliveryJobRejectsInvalidUrlAndEmptySecret(): void
    {
        $job = new WebhookDeliveryJob();
        try {
            $job->handle(['url' => 'not-a-url', 'secret' => self::SECRET, 'event' => 'x', 'payload' => []]);
            $this->fail('Expected InvalidArgumentException for a non-URL.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $job->handle(['url' => 'https://example.com/hook', 'secret' => '', 'event' => 'x', 'payload' => []]);
            $this->fail('Expected InvalidArgumentException for an empty secret.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testBackoffSchedule(): void
    {
        $this->assertSame([5, 10, 20, 40, 80, 160, 300], [
            WebhookDeliveryJob::backoffSeconds(1),
            WebhookDeliveryJob::backoffSeconds(2),
            WebhookDeliveryJob::backoffSeconds(3),
            WebhookDeliveryJob::backoffSeconds(4),
            WebhookDeliveryJob::backoffSeconds(5),
            WebhookDeliveryJob::backoffSeconds(6),
            WebhookDeliveryJob::backoffSeconds(7),
        ]);
        $this->assertSame(300, WebhookDeliveryJob::backoffSeconds(10));
        $this->assertSame(5, WebhookDeliveryJob::backoffSeconds(0));
    }

    public function testDispatcherFromConfig(): void
    {
        $configDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_webhook_cfg_' . uniqid('', true);
        mkdir($configDir, 0777, true);
        file_put_contents(
            $configDir . '/webhooks.php',
            "<?php return ['endpoints' => [['url' => 'https://example.com/cfg', 'secret' " .
                "=> 'cfg-secret', 'events' => ['order.created']]], 'default_secret' => '', " .
                "'max_attempts' => 3, 'timeout' => " . "10];"
        );
        Config::load($configDir);

        Queue::fake();
        $queued = WebhookDispatcher::fromConfig()->dispatch('order.created', ['order_id' => 3]);

        $this->assertSame(1, $queued);
        Queue::assertPushed(
            WebhookDeliveryJob::class,
            static fn (array $data): bool => $data['url'] === 'https://example.com/cfg'
        );
        unlink($configDir . '/webhooks.php');
        rmdir($configDir);
    }

    public function testEndToEndDeliveryWithRealReceiver(): void
    {
        $dbFile = $this->freshQueueDbFile();
        $this->useFileQueue($dbFile);
        $port = $this->startServer(false);
        $url = 'http://127.0.0.1:' . $port . '/hook';
        $dispatcher = new WebhookDispatcher([
            'endpoints' => [['url' => $url, 'secret' => self::SECRET, 'events' => ['order.created']]],
            'max_attempts' => 3,
            'timeout' => 5,
        ]);

        $this->assertSame(1, $dispatcher->dispatch('order.created', ['order_id' => 7]));
        // The delivery runs in a separate worker process (like production
        // queue:work) — that process disables Http SSRF protection so it
        // can reach the local receiver; this process never touches it.
        $result = $this->runWorker($dbFile);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame(0, $result['failed']);

        $lines = $this->readLogLines();
        $this->assertCount(1, $lines, 'Receiver must have logged exactly one delivery.');
        $this->assertSame('order.created', $lines[0]['event']);
        $this->assertTrue($lines[0]['valid'], 'Receiver must verify the signature.');
        $this->assertTrue(
            WebhookSigner::verify((string) $lines[0]['body'], (string) $lines[0]['signature'], self::SECRET),
            'Signature in the log must verify against the raw body.'
        );
        $body = json_decode((string) $lines[0]['body'], true);
        $this->assertSame(['order_id' => 7], $body);
    }

    public function testFailedDeliveryRetriesWithBackoffThenDeadLetters(): void
    {
        $dbFile = $this->freshQueueDbFile();
        $this->useFileQueue($dbFile);
        $port = $this->startServer(true);
        $url = 'http://127.0.0.1:' . $port . '/hook';
        $dispatcher = new WebhookDispatcher([
            'endpoints' => [['url' => $url, 'secret' => self::SECRET, 'events' => ['order.created']]],
            'max_attempts' => 3,
            'timeout' => 5,
        ]);
        $dispatcher->dispatch('order.created', ['order_id' => 9]);

        // Attempt 1 fails (500) → job stays, attempts=1, delayed by backoff.
        $result = $this->runWorker($dbFile);
        $this->assertSame(1, $result['processed']);
        $row = Database::first('SELECT attempts, available_at FROM jobs');
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['attempts']);
        $delay = (int) $row['available_at'] - time();
        $this->assertGreaterThanOrEqual(4, $delay, 'First retry must be delayed ~5s.');
        $this->assertLessThanOrEqual(6, $delay);

        // Fast-forward past the backoff window and retry twice more.
        for ($i = 2; $i <= 3; $i++) {
            Database::execute('UPDATE jobs SET available_at = :t', ['t' => time() - 1]);
            $result = $this->runWorker($dbFile);
            $this->assertSame(1, $result['processed'], "Attempt {$i} should run.");
        }

        $this->assertSame(0, Queue::pendingCount(), 'Job must be gone after max attempts.');
        $this->assertSame(1, Queue::failedCount(), 'Job must dead-letter to failed_jobs.');
        $failed = Database::first('SELECT error FROM failed_jobs');
        $this->assertNotNull($failed);
        $this->assertStringContainsString('HTTP 500', (string) $failed['error']);
        $this->assertCount(3, $this->readLogLines(), 'Receiver must have seen all 3 attempts.');
    }

    /** @return list<array<string, mixed>> */
    private function readLogLines(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }
        $lines = [];
        foreach (explode("\n", trim((string) file_get_contents($this->logFile))) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }
        return $lines;
    }

    private function freshQueueDbFile(): string
    {
        $this->dbFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_webhook_q_' . uniqid('', true) . '.sqlite';
        return $this->dbFile;
    }

    private function useFileQueue(string $dbFile): void
    {
        Database::purgeAll();
        Database::configure(['driver' => 'sqlite', 'database' => $dbFile]);
        Database::execute('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL,
            data TEXT,
            attempts INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3,
            priority INTEGER DEFAULT 0,
            timeout INTEGER DEFAULT 60,
            available_at INTEGER NOT NULL,
            locked_until INTEGER,
            created_at TEXT
        )');
        Database::execute('CREATE TABLE failed_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL,
            data TEXT,
            error TEXT,
            failed_at TEXT
        )');
        Queue::reset();
    }

    /**
     * Run the queue worker in a separate process (like production queue:work)
     * and return its JSON summary.
     *
     * @return array{processed: int, pending: int, failed: int}
     */
    private function runWorker(string $dbFile, int $maxCalls = 10): array
    {
        // Close this process's PDO first — the worker needs exclusive
        // write access to the sqlite file (same reason Queue::workAll
        // purges connections before forking).
        Database::purgeAll();
        $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'webhook-worker.php';
        $cmd = 'timeout 30 php8.4 ' . escapeshellarg($worker) . ' ' . escapeshellarg($dbFile) . ' ' . $maxCalls .
            ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, 'webhook-worker failed: ' . implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, 'webhook-worker output was not JSON: ' . implode("\n", $output));
        Database::configure(['driver' => 'sqlite', 'database' => $dbFile]);
        /** @var array{processed: int, pending: int, failed: int} $decoded */
        return $decoded;
    }

    /**
     * Start the receiver on a random free port and return the port.
     * Uses proc_open with an ARRAY command so the server runs directly
     * (no `sh -c` wrapper): proc_terminate then signals the php process
     * itself — with a string command it only kills the shell and the
     * server survives as an orphan holding the port.
     */
    private function startServer(bool $alwaysFail): int
    {
        $port = 0;
        for ($i = 0; $i < 20; $i++) {
            $candidate = random_int(18100, 18299);
            $probe = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
            if ($probe === false) {
                $port = $candidate;
                break;
            }
            fclose($probe);
        }
        if ($port === 0) {
            $this->markTestSkipped('Could not find a free port for the webhook receiver.');
        }

        $receiver = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'webhook-receiver.php';
        $docroot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures';
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w']];
        $env = [
            'PATH' => (string) getenv('PATH'),
            'WEBHOOK_TEST_SECRET' => self::SECRET,
            'WEBHOOK_TEST_LOG' => $this->logFile,
            'WEBHOOK_TEST_ALWAYS_FAIL' => $alwaysFail ? '1' : '0',
        ];
        $process = proc_open(
            ['php8.4', '-S', '127.0.0.1:' . $port, '-t', $docroot, $receiver],
            $descriptors,
            $pipes,
            $docroot,
            $env
        );
        if (!is_resource($process)) {
            $this->markTestSkipped('Could not start webhook receiver server.');
        }
        $this->serverProcess = $process;
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($sock)) {
                fclose($sock);
                $ready = true;
                break;
            }
            usleep(100000);
        }
        if (!$ready) {
            $this->markTestSkipped('Webhook receiver server did not become ready.');
        }
        return $port;
    }

    private function stopServer(): void
    {
        if (!is_resource($this->serverProcess)) {
            return;
        }
        proc_terminate($this->serverProcess);
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->serverProcess);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }
        $status = proc_get_status($this->serverProcess);
        if ($status['running']) {
            proc_terminate($this->serverProcess, 9);
        }
        proc_close($this->serverProcess);
        $this->serverProcess = null;
    }
}
