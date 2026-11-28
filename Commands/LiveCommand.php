<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

/**
 * Development server with live reload.
 *
 * Starts PHP's built-in server and watches for file changes.
 * On change, the server is automatically restarted.
 *
 * Usage:
 *   php siro live              # Start on :9090 with file watching
 *   php siro live --port=9090  # Custom port
 *   php siro live --host=127.0.0.1 --port=9090
 *
 * @package Siro\Core\Commands
 */
final class LiveCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    private int $port = 9090;
    private string $host = 'localhost';
    private bool $shutdown = false;

    public function __construct(private readonly string $basePath)
    {
    }

    /** Signal the watch loop to terminate (used by tests). */
    public function shutdown(): void
    {
        $this->shutdown = true;
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->port = 9090;
        $this->host = 'localhost';
        $rawPort = '9090';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $rawPort = substr($arg, 7);
            }
            if (str_starts_with($arg, '--host=')) {
                $this->host = substr($arg, 7);
            }
        }

        // Validate host/port exactly like ServeCommand does: an invalid value
        // must fail fast with exit 1 instead of silently coercing (e.g.
        // --port=abc used to become port 1 and still print "Server started").
        if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $this->host)) {
            $this->write('Invalid host.');
            return 1;
        }

        if (!ctype_digit($rawPort)) {
            $this->write('Invalid port.');
            return 1;
        }

        $port = (int) $rawPort;
        if ($port < 1 || $port > 65535) {
            $this->write('Invalid port.');
            return 1;
        }
        $this->port = $port;

        $publicDir = $this->basePath . DIRECTORY_SEPARATOR . 'public';
        $routerFile = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
        $watchDirs = [
            $this->basePath . DIRECTORY_SEPARATOR . 'app',
            $this->basePath . DIRECTORY_SEPARATOR . 'routes',
            $this->basePath . DIRECTORY_SEPARATOR . 'config',
        ];

        if (!is_dir($publicDir)) {
            $this->write('Public directory not found: ' . $publicDir);
            return 1;
        }

        $this->write(" \033[1;33mSiro Live Dev Server\033[0m");
        $this->write("   Host: http://{$this->host}:{$this->port}");
        $this->write("   Root: {$publicDir}");
        $this->write("   Watch: app/, routes/, config/");
        $this->write("   Press Ctrl+C to stop");
        $this->write('');

        $lastRestart = 0;
        $serverCmd = 'php -S ' . escapeshellarg($this->host) . ':' . escapeshellarg((string) $this
            ->port) . ' -t ' . escapeshellarg($publicDir);

        if (is_file($routerFile)) {
            $serverCmd .= " \"{$routerFile}\"";
        }

        // Use a file-based timestamp to signal restarts
        $signalFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_live_' . md5($this->basePath) . '.tmp';

        if (!$this->shutdown) {
            // Install signal handlers BEFORE starting the server: Console::run()
            // registers SIGTERM/SIGINT handlers but never enables async signals
            // or dispatches, so without this the watch loop below ignores both
            // signals and only SIGKILL can stop it. Our handler only flips the
            // flag; the loop below stops the child server and exits cleanly.
            // (Installed only when actually starting, so in-process unit runs
            // that pre-set shutdown() do not touch the caller's handlers.)
            if (extension_loaded('pcntl')) {
                pcntl_async_signals(true);
                $requestShutdown = function (): void {
                    $this->shutdown = true;
                };
                pcntl_signal(SIGTERM, $requestShutdown);
                pcntl_signal(SIGINT, $requestShutdown);
            }

            $started = $this->startServer($serverCmd, $signalFile);
            if ($started === 2) {
                $this->stopServer($signalFile);
                $this->write('  Live server stopped.');
                return 0;
            }
            if ($started !== 0) {
                return 1;
            }
        }

        $this->write('  Watching for file changes...');

        while (true) {
            if ($this->shutdown) {
                $this->stopServer($signalFile);
                $this->write('  Live server stopped.');
                return 0;
            }
            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }
            if (file_exists($signalFile) && filemtime($signalFile) > $lastRestart) {
                // Server was already restarted by watcher
                $lastRestart = (int) filemtime($signalFile);
            }

            // Check for changes in watch directories
            $changed = $this->checkChanges($watchDirs, $lastRestart);
            if ($changed) {
                $now = time();
                if ($now - $lastRestart > 1) {
                    $this->write("  \033[32mChange detected: {$changed}. Restarting...\033[0m");
                    file_put_contents($signalFile, (string) $now);
                    $lastRestart = $now;

                    // Kill existing server and restart
                    $this->stopServer($signalFile);

                    // Small delay to let port release
                    usleep(200000);
                    $started = $this->startServer($serverCmd, $signalFile);
                    if ($started === 2) {
                        $this->stopServer($signalFile);
                        $this->write('  Live server stopped.');
                        return 0;
                    }
                    if ($started !== 0) {
                        return 1;
                    }
                }
            }

            // Check every 0.5s, in short slices so a signal that arrived
            // mid-sleep is noticed within ~100ms even if usleep() is not
            // interrupted by the handler.
            for ($i = 0; $i < 5; $i++) {
                usleep(100000);
                if ($this->shutdown) {
                    break;
                }
                if (extension_loaded('pcntl')) {
                    pcntl_signal_dispatch();
                }
            }
        }
    }

    /**
     * @param array<int, string> $dirs
     */
    private function checkChanges(array $dirs, int $since): string
    {
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getMTime() > $since) {
                    $ext = $file->getExtension();
                    if (in_array($ext, ['php', 'env', 'json', 'neon'], true)) {
                        return $file->getFilename();
                    }
                }
            }
        }

        return '';
    }

    /**
     * Start the detached `php -S` child.
     *
     * @return 0|1|2 0 = started, 1 = failed to start (error printed;
     * previously "Server started" was printed unconditionally, even for a
     * host/port that could never bind), 2 = shutdown requested while starting.
     */
    private function startServer(string $cmd, string $signalFile): int
    {
        $pidFile = $signalFile . '.pid';
        $pid = '';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: launch a detached php -S process using the full server
            // command, then find its PID by the listening port via netstat.
            $proc = popen("cmd /c start /B \"SiroLive\" /D \"{$this->basePath}\" {$cmd} >NUL 2>&1", 'r');
            if ($proc !== false) {
                pclose($proc);
            }
            // Give the process a moment to bind the port, then capture its PID.
            usleep(600000);
            $netstat = (string) shell_exec("netstat -ano | findstr :{$this->port} | findstr LISTENING 2>NUL");
            $lines = explode("\n", $netstat);
            foreach ($lines as $line) {
                if (preg_match('/\s+(\d+)\s*$/', trim($line), $m)) {
                    file_put_contents($pidFile, $m[1]);
                    break;
                }
            }
        } else {
            $wrapped = "{$cmd} > /dev/null 2>&1 & echo $!";
            $pid = trim((string) shell_exec($wrapped));
            if ($pid !== '') {
                file_put_contents($pidFile, $pid);
            }
        }

        file_put_contents($signalFile, (string) time());

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->write("  \033[32mServer started\033[0m");
            return 0;
        }

        // Confirm the child is actually listening before claiming success:
        // poll the port briefly; fail only when the child process is gone
        // (bind error). A live child that is slow to bind still counts as
        // started once the wait expires, so slow machines get no false error.
        $probeHost = $this->host === 'localhost' ? '127.0.0.1' : $this->host;
        for ($i = 0; $i < 20; $i++) {
            if ($pid !== '' && !$this->isProcessAlive($pid)) {
                return $this->failStart();
            }
            $sock = @fsockopen($probeHost, $this->port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                fclose($sock);
                // The port may be held by another process while our child
                // just died (port already in use) — the probe alone cannot
                // tell them apart, and the child may need a moment to exit
                // on a bind error. Wait briefly, then re-check liveness.
                usleep(500000);
                if ($pid !== '' && !$this->isProcessAlive($pid)) {
                    return $this->failStart();
                }
                $this->write("  \033[32mServer started\033[0m");
                return 0;
            }
            if ($this->shutdown) {
                return 2;
            }
            usleep(100000);
        }

        $this->write("  \033[32mServer started\033[0m");
        return 0;
    }

    /**
     * Report a child that died before listening.
     *
     * @return 1 always (the start-failure code).
     */
    private function failStart(): int
    {
        $this->write("  Failed to start server on http://{$this->host}:{$this->port}.");
        $this->write('  (Child process exited — host unreachable or port in use.)');
        return 1;
    }

    private function isProcessAlive(string $pid): bool
    {
        if (!ctype_digit($pid)) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill((int) $pid, 0);
        }
        return is_dir('/proc/' . $pid);
    }

    private function stopServer(string $signalFile): void
    {
        $pidFile = $signalFile . '.pid';

        if (is_file($pidFile)) {
            $pid = trim((string) file_get_contents($pidFile));
            if ($pid !== '') {
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    exec("taskkill /F /PID {$pid} 2>NUL");
                } else {
                    exec("kill {$pid} 2>/dev/null");
                }
            }
            @unlink($pidFile);
        }

        // Do NOT kill all php.exe — that would terminate the watcher itself
        // (and any other PHP processes on the machine).
    }
}
