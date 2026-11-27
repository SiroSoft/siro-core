<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\LiveCommand;
use Siro\Core\Commands\NewProjectCommand;
use Siro\Core\Commands\StorageLinkCommand;

/**
 * Regression tests for the server CLI smoke fixes (2026-11-27 lane SERVER).
 *
 * Covers SRV-M1 (live ignores SIGTERM/SIGINT), SRV-M2 (live does not
 * validate host/port), SRV-M3 (storage:link false "already exists") and
 * SRV-M4 (usage/docblock strings in the owned command files).
 *
 * The `live` signal tests spawn a real child PHP process and signal it:
 * never run the watch loop in-process for those cases, it would hang the
 * test runner. Long-running child processes are always terminated by PID
 * and the port is asserted closed afterwards — no orphans.
 */
final class SmokeFixServerTest extends TestCase
{
    private string $basePath;

    /** @var array<int, resource> */
    private array $procs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/smoke_fix_server_' . bin2hex(random_bytes(4));
        foreach (['public', 'app', 'routes', 'config', 'storage/public'] as $dir) {
            mkdir($this->basePath . '/' . $dir, 0777, true);
        }
        file_put_contents($this->basePath . '/public/index.php', '<?php echo "ok";');
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            if (is_resource($proc)) {
                $status = proc_get_status($proc);
                if ($status['running']) {
                    proc_terminate($proc, 9);
                }
                proc_close($proc);
            }
        }
        $this->procs = [];
        // Restore default signal dispositions: LiveCommand installs its own
        // handlers when it starts a server, which would otherwise leak into
        // the phpunit process for later tests in the same run.
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_async_signals(false);
        }
        $signalFile = sys_get_temp_dir() . '/siro_live_' . md5($this->basePath) . '.tmp';
        @unlink($signalFile);
        @unlink($signalFile . '.pid');
        self::rmdir($this->basePath);
        parent::tearDown();
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isLink() || $file->isFile()) {
                @unlink($file->getPathname());
            } else {
                @rmdir($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** @return array{0: int, 1: string} */
    private function runLive(array $args): array
    {
        $cmd = new LiveCommand($this->basePath);
        ob_start();
        $code = $cmd->run($args);
        $output = (string) ob_get_clean();
        return [$code, $output];
    }

    /** @return array{0: int, 1: string} */
    private function runStorageLink(): array
    {
        $cmd = new StorageLinkCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        $output = (string) ob_get_clean();
        return [$code, $output];
    }

    private static function isListening(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    private static function waitForListening(string $host, int $port, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (self::isListening($host, $port)) {
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    // ==================== SRV-M2: live host/port validation ====================

    public function testLiveRejectsNonNumericPort(): void
    {
        [$code, $output] = $this->runLive(['--host=127.0.0.1', '--port=abc']);
        $this->assertSame(1, $code, 'live --port=abc must exit 1, not coerce to port 1');
        $this->assertStringContainsString('Invalid port.', $output);
    }

    public function testLiveRejectsPortZeroAndOutOfRange(): void
    {
        foreach (['0', '70000', '99999'] as $port) {
            [$code, $output] = $this->runLive(['--host=127.0.0.1', '--port=' . $port]);
            $this->assertSame(1, $code, "live --port={$port} must exit 1");
            $this->assertStringContainsString('Invalid port.', $output);
        }
    }

    public function testLiveRejectsBadHost(): void
    {
        [$code, $output] = $this->runLive(['--host=bad host!', '--port=18140']);
        $this->assertSame(1, $code, 'live with an invalid host must exit 1 like serve does');
        $this->assertStringContainsString('Invalid host.', $output);
    }

    public function testLiveRejectsInvalidPortWithoutStartingServer(): void
    {
        [$code] = $this->runLive(['--port=abc']);
        $this->assertSame(1, $code);
        $signalFile = sys_get_temp_dir() . '/siro_live_' . md5($this->basePath) . '.tmp';
        $this->assertFileDoesNotExist($signalFile . '.pid', 'No server child may be spawned for an invalid port');
        $this->assertFalse(self::isListening('127.0.0.1', 1), 'Port 1 must not be bound by the coerced value');
    }

    public function testLiveFailsFastWhenPortAlreadyInUse(): void
    {
        $port = 18149;
        $occupier = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->basePath . '/public'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($occupier);
        $this->procs[] = $occupier;
        $this->assertTrue(self::waitForListening('127.0.0.1', $port, 5.0), 'Occupier php -S should be listening');

        $start = microtime(true);
        [$code, $output] = $this->runLive(['--host=127.0.0.1', '--port=' . $port]);
        $elapsed = microtime(true) - $start;

        $this->assertSame(1, $code, 'live on a busy port must exit 1, not print "Server started"');
        $this->assertStringContainsString('Failed to start server', $output);
        $this->assertLessThan(8.0, $elapsed, 'Busy-port failure must be fast, not hang in the watch loop');
    }

    // ==================== SRV-M1: live stops on SIGTERM / SIGINT ====================

    /** @return array<string, array{int}> */
    public static function stopSignalProvider(): array
    {
        return [
            'SIGTERM' => [SIGTERM],
            'SIGINT' => [SIGINT],
        ];
    }

    #[DataProvider('stopSignalProvider')]
    public function testLiveStopsOnSignal(int $signal): void
    {
        $port = $signal === SIGTERM ? 18147 : 18148;
        $runner = $this->basePath . '/live_runner.php';
        file_put_contents($runner, '<?php' . "\n"
            . 'declare(strict_types=1);' . "\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';' . "\n"
            . '$cmd = new \\Siro\\Core\\Commands\\LiveCommand($argv[1]);' . "\n"
            . 'exit($cmd->run(["--host=127.0.0.1", "--port=" . $argv[2]]));' . "\n");
        $this->assertTrue(self::waitForListening('127.0.0.1', $port, 0.1) === false);

        $proc = proc_open(
            [PHP_BINARY, $runner, $this->basePath, (string) $port],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);
        $this->procs[] = $proc;
        $listening = self::waitForListening('127.0.0.1', $port, 5.0);
        $this->assertTrue($listening, 'live server should be listening before signalling');

        $signalledAt = microtime(true);
        $this->assertTrue(proc_terminate($proc, $signal), 'proc_terminate should deliver the signal');

        $exitCode = null;
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            usleep(100000);
        }
        $elapsed = microtime(true) - $signalledAt;

        if ($exitCode === null) {
            proc_terminate($proc, 9);
        }
        $this->assertNotNull($exitCode, 'live must exit after the signal instead of hanging until SIGKILL');
        $this->assertSame(0, $exitCode, 'live should exit 0 on a graceful signal stop');
        $this->assertLessThan(5.0, $elapsed, 'live must stop within ~5s of the signal');
        $this->assertFalse(self::isListening('127.0.0.1', $port), 'Child php -S must be stopped too, not orphaned');
        $signalFile = sys_get_temp_dir() . '/siro_live_' . md5($this->basePath) . '.tmp';
        $this->assertFileDoesNotExist($signalFile . '.pid', 'Server pid file must be cleaned up on stop');
    }

    // ==================== SRV-M3: storage:link ====================

    public function testStorageLinkRejectsRealDirectory(): void
    {
        mkdir($this->basePath . '/public/storage', 0777, true);
        file_put_contents($this->basePath . '/public/storage/.gitkeep', '');
        file_put_contents($this->basePath . '/storage/public/keep.txt', 'keep');

        [$code, $output] = $this->runStorageLink();

        $this->assertSame(1, $code, 'A real dir must not be reported as an existing link, exit 0');
        $this->assertStringContainsString('not a symlink', $output);
        $this->assertDirectoryExists($this->basePath . '/public/storage', 'User data must not be removed');
        $this->assertFileExists($this->basePath . '/public/storage/.gitkeep');
        $this->assertFalse(is_link($this->basePath . '/public/storage'));
    }

    public function testStorageLinkCreatesSymlinkWhenMissing(): void
    {
        [$code, $output] = $this->runStorageLink();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Link created', $output);
        $this->assertTrue(is_link($this->basePath . '/public/storage'));
        file_put_contents($this->basePath . '/storage/public/probe.txt', 'probe');
        $probe = $this->basePath . '/public/storage/probe.txt';
        $this->assertFileExists($probe, 'storage/public must resolve through the link');
    }

    public function testStorageLinkReportsCorrectSymlinkAsExisting(): void
    {
        [$firstCode] = $this->runStorageLink();
        $this->assertSame(0, $firstCode);

        [$code, $output] = $this->runStorageLink();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Link already exists', $output);
        $this->assertTrue(is_link($this->basePath . '/public/storage'));
    }

    public function testStorageLinkRejectsSymlinkToWrongTarget(): void
    {
        $elsewhere = $this->basePath . '/elsewhere';
        mkdir($elsewhere, 0777, true);
        symlink($elsewhere, $this->basePath . '/public/storage');

        [$code, $output] = $this->runStorageLink();

        $this->assertSame(1, $code, 'A link pointing elsewhere must not be reported as success');
        $this->assertStringContainsString('symlink elsewhere', $output);
        $actualTarget = readlink($this->basePath . '/public/storage');
        $this->assertSame($elsewhere, $actualTarget, 'Foreign link must be left untouched');
    }

    public function testStorageLinkRejectsRegularFile(): void
    {
        file_put_contents($this->basePath . '/public/storage', 'not a dir');

        [$code, $output] = $this->runStorageLink();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a symlink', $output);
        $this->assertFileExists($this->basePath . '/public/storage');
    }

    // ==================== SRV-M4: usage / docblock in owned files ====================

    public function testNewProjectWithoutNamePrintsCorrectCommand(): void
    {
        $cmd = new NewProjectCommand($this->basePath);
        ob_start();
        $code = $cmd->run([]);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('php siro new:project', $output);
        $this->assertStringNotContainsString('php siro new <', $output);
    }

    public function testLiveDocblockMatchesRealDefaultPort(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Commands/LiveCommand.php');
        $this->assertStringContainsString(':9090', $source, 'Docblock must say :9090, the real default');
        $this->assertStringNotContainsString(':8080', $source, 'Stale :8080 default must not remain in the docblock');
        $this->assertStringContainsString('--host=', $source, 'Docblock must document --host=');
    }

    public function testServeDocblockDocumentsHostFlag(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Commands/ServeCommand.php');
        $this->assertStringContainsString('--host=', $source);
        $this->assertStringContainsString('--port=', $source);
    }

    public function testFrankenphpDocblockDocumentsDockerPort(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Commands/FrankenphpServeCommand.php');
        $this->assertStringContainsString('--docker-port=', $source);
    }
}
