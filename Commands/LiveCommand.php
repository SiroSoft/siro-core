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
 *   php siro live              # Start on :8080 with file watching
 *   php siro live --port=9090  # Custom port
 *
 * @package Siro\Core\Commands
 */
final class LiveCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    private int $port = 9090;
    private string $host = 'localhost';

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->port = 9090;
        $this->host = 'localhost';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $this->port = max(1, (int) substr($arg, 7));
            }
            if (str_starts_with($arg, '--host=')) {
                $this->host = substr($arg, 7);
            }
        }

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
        $serverCmd = "php -S {$this->host}:{$this->port} -t \"{$publicDir}\"";

        if (is_file($routerFile)) {
            $serverCmd .= " \"{$routerFile}\"";
        }

        // Use a file-based timestamp to signal restarts
        $signalFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro_live_' . md5($this->basePath) . '.tmp';

        $this->startServer($serverCmd, $signalFile);

        $this->write('  Watching for file changes...');

        // @phpstan-ignore-next-line while.alwaysTrue
        while (true) {
            if (filemtime($signalFile) > $lastRestart) {
                // Server was already restarted by watcher
                $lastRestart = filemtime($signalFile);
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
                    $this->startServer($serverCmd, $signalFile);
                }
            }

            usleep(500000); // Check every 0.5s
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

    private function startServer(string $cmd, string $signalFile): void
    {
        $pidFile = $signalFile . '.pid';

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
        $this->write("  \033[32mServer started\033[0m");
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
