<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

/**
 * Lightweight job for long-run testing.
 */

final class QLongRunJob implements \Siro\Core\QueueInterface
{
    /** @var array<int, array<string, mixed>> */
    public static array $executions = [];

    /** @var array<int, string> */
    public static array $stateLeakages = [];

    private static ?array $internalState = null;

    public function handle(array $data = []): void
    {
        $seq = (int) ($data['seq'] ?? 0);

        // Check for state leakage from previous job
        if (self::$internalState !== null) {
            self::$stateLeakages[] = "Job {$seq} leaked from previous: " . json_encode(self::$internalState);
        }

        // Process job
        self::$executions[$seq] = $data;

        // Set state (should NOT leak to next job in same worker)
        self::$internalState = $data;

        // Simulate minimal work
        $hash = hash('sha256', json_encode($data));

        // Clear state for next job
        self::$internalState = null;
    }
}
