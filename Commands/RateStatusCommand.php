<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

final class RateStatusCommand implements \Siro\Core\Commands\CommandInterface {
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $rateDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';

        if (!is_dir($rateDir)) {
            $this->write('No rate limit data found.');
            $this->write('Rate limiting has not been triggered yet.');
            return 0;
        }

        $files = glob($rateDir . DIRECTORY_SEPARATOR . '*.json') ?: [];

        if ($files === []) {
            $this->write('No rate limit entries found.');
            return 0;
        }

        $now = time();
        $total = count($files);
        $active = 0;
        $exceeded = 0;
        $rows = [];

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            $count = (int) ($data['count'] ?? 0);
            $expiresAt = (int) ($data['expires_at'] ?? 0);
            $remaining = max(0, $expiresAt - $now);
            $hash = basename($file, '.json');

            if ($remaining > 0) {
                $active++;
            }

            $status = $remaining > 0 ? ($count > 60 ? "\033[31mBLOCKED\033[0m" : "\033[32mOK\033[0m") : "\033[90mEXPIRED\033[0m";

            $rows[] = [
                substr($hash, 0, 16) . '...',
                (string) $count,
                $remaining > 0 ? ($remaining . 's') : '-',
                $status,
            ];
        }

        $this->write('Rate Limiting Status');
        $this->write('  Total entries: ' . $total);
        $this->write('  Active:        ' . $active);
        $this->write('');

        if ($rows !== []) {
            $this->table(['Key', 'Count', 'TTL', 'Status'], $rows);
        }

        $this->write('');
        $this->write('Clear all: rm -rf ' . $rateDir . '/*.json');

        return 0;
    }
}
