<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use Siro\Core\Middleware\ThrottleMiddleware;

final class RateStatusCommand implements \Siro\Core\Commands\CommandInterface
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $this->writeTiers();

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

            $countVal = $data['count'] ?? 0;
            $expiresVal = $data['expires_at'] ?? 0;
            $count = is_numeric($countVal) ? (int) $countVal : 0;
            $expiresAt = is_numeric($expiresVal) ? (int) $expiresVal : 0;
            $remaining = max(0, $expiresAt - $now);
            $hash = basename($file, '.json');
            // Files written before G6 have no limiter/limit fields.
            $limiterField = $data['limiter'] ?? '';
            $tierName = is_string($limiterField) && $limiterField !== '' ? $limiterField : 'default';
            $limitVal = $data['limit'] ?? 60;
            $entryLimit = is_numeric($limitVal) ? max(1, (int) $limitVal) : 60;

            if ($remaining > 0) {
                $active++;
            }

            $status = $remaining > 0
                ? ($count > $entryLimit ? "\033[31mBLOCKED\033[0m" : "\033[32mOK\033[0m")
                : "\033[90mEXPIRED\033[0m";

            $rows[] = [
                substr($hash, 0, 16) . '...',
                (string) $count,
                $remaining > 0 ? ($remaining . 's') : '-',
                $tierName,
                $status,
            ];
        }

        $this->write('Rate Limiting Status');
        $this->write('  Total entries: ' . $total);
        $this->write('  Active:        ' . $active);
        $this->write('');

        if ($rows !== []) {
            $this->table(['Key', 'Count', 'TTL', 'Tier', 'Status'], $rows);
        }

        $this->write('');
        $this->write('Clear all: rm -rf ' . $rateDir . '/*.json');

        return 0;
    }

    /** List the named limiters defined via ThrottleMiddleware::define(). */
    private function writeTiers(): void
    {
        $limiters = ThrottleMiddleware::limiters();
        $planMap = ThrottleMiddleware::planLimiters();

        if ($limiters === [] && $planMap === []) {
            return;
        }

        $this->write('Defined limiters:');
        foreach ($limiters as $name => $limiter) {
            $key = is_string($limiter['key']) ? $limiter['key'] : 'callable';
            $plans = [];
            foreach ($planMap as $plan => $mapped) {
                if ($mapped === $name) {
                    $plans[] = $plan;
                }
            }
            $line = '  ' . $name . ': ' . $limiter['limit'] . ' req / '
                . $limiter['minutes'] . ' min (key: ' . $key . ')';
            if ($plans !== []) {
                $line .= ' [plans: ' . implode(', ', $plans) . ']';
            }
            $this->write($line);
        }
        $this->write('');
    }
}
