<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class LogStatsCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $dailyDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'daily';
        $days = 1;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--days=')) {
                $days = max(1, (int) substr($arg, 7));
            }
        }

        $cutoff = time() - ($days * 86400);
        $files = glob($dailyDir . DIRECTORY_SEPARATOR . '????-??' . DIRECTORY_SEPARATOR . 'request-*.log') ?: [];

        $this->write("  \033[1;33mRequest Statistics (last {$days} day(s))\033[0m");
        $this->write('');

        $totalRequests = 0;
        $statusCounts = [];
        $methodCounts = [];
        $totalTime = 0.0;
        $slowCount = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) continue;

            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) continue;

            foreach ($lines as $line) {
                $totalRequests++;
                if (preg_match('/\b(\d{3})\b/', $line, $m)) {
                    $s = (int) $m[1];
                    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
                }
                if (preg_match('/\b(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\b/', $line, $m)) {
                    $methodCounts[$m[1]] = ($methodCounts[$m[1]] ?? 0) + 1;
                }
                if (preg_match('/([\d.]+)ms/', $line, $m)) {
                    $ms = (float) $m[1];
                    $totalTime += $ms;
                    if ($ms > 100) $slowCount++;
                }
            }
        }

        if ($totalRequests === 0) {
            $this->write('  No request logs found for this period.');
            return 0;
        }

        $this->write("  \033[1mSummary\033[0m");
        $this->write("    Total Requests:  {$totalRequests}");
        $this->write("    Avg Response:    " . number_format($totalTime / $totalRequests, 2) . "ms");
        $this->write("    Slow (>100ms):   {$slowCount}");
        $this->write('');

        // Status code bar chart
        $this->write("  \033[1mBy Status Code\033[0m");
        ksort($statusCounts);
        foreach ($statusCounts as $code => $count) {
            $color = $code >= 500 ? '31' : ($code >= 400 ? '33' : '32');
            $bar = str_repeat('█', max(1, (int) ($count / max(1, $totalRequests) * 30)));
            $pct = round($count / $totalRequests * 100, 1);
            $this->write("    \033[{$color}m{$code}\033[0m {$bar} {$count} ({$pct}%)");
        }
        $this->write('');

        // Method bar chart
        $this->write("  \033[1mBy Method\033[0m");
        foreach ($methodCounts as $method => $count) {
            $bar = str_repeat('█', max(1, (int) ($count / max(1, $totalRequests) * 30)));
            $pct = round($count / $totalRequests * 100, 1);
            $this->write("    \033[36m{$method}\033[0m    {$bar} {$count} ({$pct}%)");
        }

        $this->write('');
        $this->write('  Run "php siro log:tail" for real-time log view.');
        return 0;
    }
}
