<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogTailCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $logDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        $type = 'request'; // default
        $lines = 20;
        $follow = false;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--type=')) {
                $type = substr($arg, 7);
            } elseif (str_starts_with($arg, '--lines=')) {
                $lines = max(5, (int) substr($arg, 8));
            } elseif ($arg === '--follow' || $arg === '-f') {
                $follow = true;
            }
        }

        // Pick the latest daily log file
        $files = glob($logDir . DIRECTORY_SEPARATOR . $type . '-*.log') ?: [];
        if ($files === []) {
            $this->write("No {$type} log files found in {$logDir}");
            return 1;
        }
        rsort($files);
        $logFile = $files[0];

        $this->write("  \033[1;33mTail: " . basename($logFile) . "\033[0m");
        $this->write("  \033[90mType: {$type} | Lines: {$lines}\033[0m");
        $this->write('');

        // Read last N lines
        $content = file($logFile);
        if ($content === false) {
            $this->write("Cannot read log file: {$logFile}");
            return 1;
        }

        $total = count($content);
        $start = max(0, $total - $lines);
        for ($i = $start; $i < $total; $i++) {
            $this->printLine($content[$i]);
        }

        if ($follow) {
            $this->write('');
            $this->write("  \033[33mWatching for new entries... (Ctrl+C to stop)\033[0m");
            $lastSize = filesize($logFile);
            // @phpstan-ignore-next-line while.alwaysTrue
            while (true) {
                clearstatcache(true, $logFile);
                $currentSize = filesize($logFile);
                if ($currentSize > $lastSize) {
                    $fh = fopen($logFile, 'r');
                    if ($fh) {
                        fseek($fh, (int) $lastSize);
                        while (($line = fgets($fh)) !== false) {
                            $this->printLine((string) $line);
                        }
                        fclose($fh);
                    }
                    $lastSize = $currentSize;
                }
                usleep(250000);
            }
        }

        return 0;
    }

    private function printLine(string $line): void
    {
        $line = rtrim($line);
        if ($line === '') return;

        // Color by status code
        if (preg_match('/\b(\d{3})\b/', $line, $m)) {
            $status = (int) $m[1];
            if ($status >= 500) {
                $line = "\033[31m{$line}\033[0m";
            } elseif ($status >= 400) {
                $line = "\033[33m{$line}\033[0m";
            } elseif ($status >= 300) {
                $line = "\033[36m{$line}\033[0m";
            } elseif ($status >= 200) {
                $line = "\033[32m{$line}\033[0m";
            }
        }

        // Color error/slow keywords
        $line = preg_replace('/\b(error|exception|fatal|critical)\b/i', "\033[31m\$0\033[0m", $line);
        $line = preg_replace('/\b(warning|warn)\b/i', "\033[33m\$0\033[0m", $line);
        $line = preg_replace('/\b(slow)\b/i', "\033[35m\$0\033[0m", $line);

        $this->write('  ' . $line);
    }
}
